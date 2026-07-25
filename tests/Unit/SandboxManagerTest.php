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
    'sandbox' => [
        'matrix' => [
            'php83' => ['php' => '8.3'],
            'php84' => ['php' => '8.4'],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);

    expect($manager->matrixNames('all'))->toBe(['php83', 'php84']);
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
})->throws(ValidationException::class, 'Unknown sandbox matrix entry');

it('writes compose files for matrix entries', function (): void {
    mkdir($this->workspace.'/plugin');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'sandbox' => [
        'matrix' => [
            'php83' => [
                'php' => '8.3',
                'database' => 'mariadb:11',
                'port' => 8091,
            ],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);
    $path = $manager->writeCompose('php83');

    expect($path)->toBeFile()
        ->and(file_get_contents($path))->toContain('wordpress:php8.3-apache')
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
    'sandbox' => [
        'matrix' => [
            'php83' => ['php' => '8.3'],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $process = new SandboxRecordingProcess;
    $manager = new SandboxManager($config, $this->workspace, $process);

    expect($manager->start('php83'))->toBe(0)
        ->and($process->commands)->toHaveCount(2)
        ->and($process->commands[1])->toContain('run', '--rm', 'wpcli');

    $script = $process->commands[1][array_key_last($process->commands[1])];
    expect($script)->toContain(
        "wp plugin install 'query-monitor' --activate --force --allow-root",
        "wp plugin install 'https://example.test/premium-addon-1.2.3.zip' --activate --force --allow-root",
        "wp plugin activate 'dependency-0' --allow-root",
        'wp plugin activate plugin-under-test --allow-root',
    );
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
