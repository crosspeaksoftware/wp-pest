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
    $path = $manager->writeCompose('default--wp-7.0.x');

    expect($path)->toBeFile()
        ->and(file_get_contents($path))->toContain('wordpress:php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-apache')
        ->and(file_get_contents($path))->toContain('"8091:80"')
        ->and(file_get_contents($path))->toContain('/plugin:/var/www/html/wp-content/plugins/plugin-under-test');
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

final class SandboxRecordingProcess implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        $this->commands[] = $command;

        return 0;
    }
}
