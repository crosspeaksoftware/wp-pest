<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Sandbox\SandboxManager;
use CrossPeak\WpPest\Support\ProcessRunner;

it('selects all matrix entries', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['6.9.*', '7.0.*'],
        'profiles' => [
            'default' => ['tests' => ['browser']],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);

    expect($manager->matrixNames('all'))->toBe(['default--wp-6.9.x', 'default--wp-7.0.x']);
});

it('rejects unknown matrix entries', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => '.'],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);

    $manager->matrixNames('missing');
})->throws(ValidationException::class, 'Unknown matrix combination');

it('writes compose files for matrix entries', function (): void {
    mkdir($this->workspace.'/plugin');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'matrix' => [
        'wordpress' => ['7.0.*'],
        'profiles' => [
            'default' => ['tests' => ['browser']],
        ],
        'browser' => ['database' => 'mariadb:11', 'port' => 8091],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);
    putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION=7.0.2');
    putenv('WP_PEST_BROWSER_HOST=docker');

    try {
        $path = $manager->writeCompose('default--wp-7.0.x');

        expect($path)->toBeFile()
            ->and(file_get_contents($path))->toContain('wordpress:php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-apache')
            ->and(file_get_contents($path))->not->toContain('wordpress:7.0.2-php')
            ->and(file_get_contents($path))->toContain('user: "0:0"')
            ->and(file_get_contents($path))->toContain('HTTP_HOST: "docker:8091"')
            ->and($manager->url('default--wp-7.0.x'))->toBe('http://docker:8091')
            ->and(file_get_contents($path))->toContain("define('WP_DEFAULT_THEME', 'twentytwentyfive');")
            ->and(file_get_contents($path))->toContain('WORDPRESS_DEBUG: "1"', 'WP_DEBUG_LOG')
            ->and(file_get_contents($path))->not->toContain('\\/proc\\/')
            ->and(file_get_contents($path))->toContain('"8091:80"')
            ->and(file_get_contents($path))->toContain('/plugin:/var/www/html/wp-content/plugins/plugin-under-test');
    } finally {
        putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION');
        putenv('WP_PEST_BROWSER_HOST');
    }
});

it('downloads the exact resolved wordpress version into the browser volume', function (): void {
    mkdir($this->workspace.'/plugin');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'matrix' => [
        'wordpress' => ['^6.9'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION=6.9.5');

    try {
        $config = (new ConfigLoader)->load($this->workspace);
        $process = new SandboxRecordingProcess;
        $manager = new SandboxManager($config, $this->workspace, $process);

        expect($manager->start('default--wp-6.9'))->toBe(0);

        $script = $process->commands[1][array_key_last($process->commands[1])];
        expect($script)->toContain(
            "php -d memory_limit=512M /usr/local/bin/wp core download --allow-root --force --version='6.9.5'",
            'fsockopen',
            'database',
            'if [ "$attempt" -ge 60 ]; then echo "Database did not become ready."',
            'php -d memory_limit=512M /usr/local/bin/wp core is-installed --allow-root',
            'until php -d memory_limit=512M /usr/local/bin/wp core install --allow-root',
            'if [ "$attempt" -ge 30 ]; then exit 1; fi',
        )->not->toContain('wp db check');
    } finally {
        putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION');
    }
});

it('removes sandbox volumes before a fresh start', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $process = new SandboxRecordingProcess;
    $manager = new SandboxManager($config, $this->workspace, $process);

    expect($manager->start('default--wp-7.0', fresh: true))->toBe(0)
        ->and($process->commands)->toHaveCount(3)
        ->and($process->commands[0])->toContain('down', '--volumes', '--remove-orphans')
        ->and($process->commands[1])->toContain('up', '-d')
        ->and($process->commands[2])->toContain('run', '--rm', 'wpcli');
});

it('seeds a fresh wordpress volume from the resolved fixture cache', function (): void {
    mkdir($this->workspace.'/cache/wp-admin', 0775, true);
    mkdir($this->workspace.'/cache/wp-includes', 0775, true);
    mkdir($this->workspace.'/cache/wp-content/themes/twentytwentyfive', 0775, true);
    file_put_contents($this->workspace.'/cache/wp-load.php', '<?php');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    putenv('WP_PEST_WORDPRESS_PATH='.$this->workspace.'/cache');
    putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION=7.0.2');

    try {
        $config = (new ConfigLoader)->load($this->workspace);
        $process = new SandboxRecordingProcess;
        $manager = new SandboxManager($config, $this->workspace, $process);

        expect($manager->start('default--wp-7.0', fresh: true))->toBe(0)
            ->and($process->commands)->toHaveCount(4)
            ->and($process->commands[1])->toContain('run', '--rm', '--no-deps', 'wpcli', 'sh', '-lc')
            ->and($process->commands[1][array_key_last($process->commands[1])])->toContain('/wp-pest-cache/wordpress/wp-admin')
            ->and($process->commands[3][array_key_last($process->commands[3])])->not->toContain('core download')
            ->and(file_get_contents($this->workspace.'/.wp-pest/sandboxes/default--wp-7.0/compose.yml'))
            ->toContain($this->workspace.'/cache:/wp-pest-cache/wordpress:ro');
    } finally {
        putenv('WP_PEST_WORDPRESS_PATH');
        putenv('WP_PEST_RESOLVED_WORDPRESS_VERSION');
    }
});

it('installs and activates configured plugin dependencies in browser sandboxes', function (): void {
    mkdir($this->workspace.'/plugin');
    mkdir($this->workspace.'/local-addon');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'plugins' => [
        'dependencies' => [
            'query-monitor',
            ['slug' => 'premium-addon', 'version' => '1.2.3', 'url' => 'https://example.test/premium-addon-1.2.3.zip'],
            './local-addon',
        ],
    ],
    'matrix' => [
        'wordpress' => ['7.0.*'],
        'profiles' => [
            'compat' => [
                'tests' => ['browser'],
                'plugins' => [
                    'query-monitor',
                    ['slug' => 'premium-addon', 'version' => '1.2.3', 'url' => 'https://example.test/premium-addon-1.2.3.zip'],
                    './local-addon',
                ],
            ],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $process = new SandboxRecordingProcess;
    $manager = new SandboxManager($config, $this->workspace, $process);

    expect($manager->start('compat--wp-7.0.x'))->toBe(0)
        ->and($process->commands)->toHaveCount(2)
        ->and($process->commands[1])->toContain('run', '--rm', 'wpcli');

    $script = $process->commands[1][array_key_last($process->commands[1])];
    expect($script)->toContain(
        "wp plugin install 'query-monitor' --activate --force --allow-root",
        "wp plugin install 'https://example.test/premium-addon-1.2.3.zip' --activate --force --allow-root",
        "wp plugin activate 'dependency-2' --allow-root",
        'wp plugin activate plugin-under-test --allow-root',
    );
});

it('mounts and activates the exact plugin copy resolved for a matrix fixture', function (): void {
    mkdir($this->workspace.'/plugin');
    mkdir($this->workspace.'/fixture/wp-content/plugins/query-monitor', 0775, true);
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'plugins' => ['query-monitor' => ['3.20.0']],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    putenv('WP_PEST_WORDPRESS_PATH='.$this->workspace.'/fixture');

    try {
        $config = (new ConfigLoader)->load($this->workspace);
        $process = new SandboxRecordingProcess;
        $manager = new SandboxManager($config, $this->workspace, $process);

        expect($manager->start('default--wp-7.0--query-monitor-3.20.0'))->toBe(0)
            ->and(file_get_contents($this->workspace.'/.wp-pest/sandboxes/default--wp-7.0--query-monitor-3.20.0/compose.yml'))
            ->toContain($this->workspace.'/fixture/wp-content/plugins/query-monitor:/var/www/html/wp-content/plugins/query-monitor');

        $script = $process->commands[1][array_key_last($process->commands[1])];
        expect($script)->toContain("wp plugin activate 'query-monitor' --allow-root")
            ->not->toContain("wp plugin install 'query-monitor'");
    } finally {
        putenv('WP_PEST_WORDPRESS_PATH');
    }
});

it('runs wp-cli commands in a matrix sandbox and captures stdout', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $process = new SandboxRecordingProcess;
    $manager = new SandboxManager($config, $this->workspace, $process);

    expect($manager->wpCli('default--wp-7.0', ['eval', 'echo "ready";']))->toBe('captured output');

    expect($process->captureCommands[1])->toBe([
        'docker',
        'compose',
        '-p',
        'wp-pest-'.basename($this->workspace).'-default--wp-7-0',
        '-f',
        $this->workspace.'/.wp-pest/sandboxes/default--wp-7.0/compose.yml',
        'run',
        '--rm',
        '--no-deps',
        'wpcli',
        'php',
        '-d',
        'memory_limit=512M',
        '/usr/local/bin/wp',
        'eval',
        'echo "ready";',
        '--allow-root',
    ]);
});

final class SandboxRecordingProcess implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<list<string>> */
    public array $captureCommands = [];

    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        $this->commands[] = $command;

        return 0;
    }

    public function capture(array $command, ?string $cwd = null): string
    {
        $this->captureCommands[] = $command;

        if ($command === ['docker', 'compose', 'version']) {
            return 'Docker Compose version v2.0.0';
        }

        return 'captured output';
    }
}
