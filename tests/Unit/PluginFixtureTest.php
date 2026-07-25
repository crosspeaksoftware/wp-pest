<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use CrossPeak\WpPest\Support\ProcessRunner;

it('configures plugin code fixtures with WordPress and SQLite', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
    'plugin' => ['path' => '.'],
    'plugins' => [
        'dependencies' => [
            'akismet',
            [
                'slug' => 'gravityforms',
                'version' => '2.10.5',
                'url' => 'https://plugins.example.test/gravityforms-2.10.5.zip',
            ],
        ],
    ],
];
PHP);

    $fixturePath = $this->workspace.'/.wp-pest/fixtures/wordpress';
    $contentPath = $fixturePath.'/wordpress/wp-content';
    mkdir($contentPath.'/plugins/sqlite-database-integration', 0775, true);
    file_put_contents($fixturePath.'/wordpress/wp-load.php', '<?php');
    file_put_contents($contentPath.'/plugins/sqlite-database-integration/db.copy', '<?php // SQLite drop-in');

    $config = (new ConfigLoader)->load($this->workspace);
    $process = new FixtureRecordingProcess;
    $path = (new PluginFixture($config, $this->workspace, $process))->ensure();
    $composer = json_decode((string) file_get_contents($fixturePath.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($path)->toBe($fixturePath)
        ->and($composer['require'])->toHaveKeys([
            'roots/wordpress',
            'wpackagist-plugin/sqlite-database-integration',
            'wpackagist-plugin/akismet',
        ])
        ->and(array_keys($composer['require']))->toContain(
            'wp-pest-plugin/gravityforms-'.substr(sha1('https://plugins.example.test/gravityforms-2.10.5.zip'), 0, 12),
        )
        ->and($composer['repositories'][1]['type'])->toBe('package')
        ->and($composer['repositories'][1]['package']['dist']['url'])
        ->toBe('https://plugins.example.test/gravityforms-2.10.5.zip')
        ->and($composer['extra']['installer-paths'])->toHaveKey('wordpress/wp-content/plugins/gravityforms/')
        ->and($composer['extra']['installer-paths'])->toHaveKey('wordpress/wp-content/plugins/{$name}/')
        ->and($composer['config']['allow-plugins']['composer/installers'])->toBeTrue()
        ->and($composer['config']['allow-plugins']['roots/wordpress-core-installer'])->toBeTrue()
        ->and($process->commands)->toBe([])
        ->and($contentPath.'/db.php')->toBeFile()
        ->and($fixturePath.'/wordpress/wp-config.php')->toBeFile()
        ->and(file_get_contents($fixturePath.'/wordpress/wp-config.php'))
        ->toContain("define('DB_FILE', 'wordpress.sqlite');", "define('WP_INSTALLING', true);");
});

final class FixtureRecordingProcess implements ProcessRunner
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        $this->commands[] = $command;

        return 0;
    }
}
