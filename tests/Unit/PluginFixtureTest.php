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
                'slug' => 'sample-plugin',
                'version' => '1.2.3',
                'url' => 'https://plugins.example.test/sample-plugin-1.2.3.zip',
            ],
        ],
    ],
];
PHP);

    $fixturePath = $this->workspace.'/.wp-pest/fixtures/default--wp-7.0';
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
            'wpackagist-theme/twentytwentyfive',
            'wpackagist-plugin/akismet',
        ])
        ->and(array_keys($composer['require']))->toContain(
            'wp-pest-plugin/sample-plugin-'.substr(sha1('https://plugins.example.test/sample-plugin-1.2.3.zip'), 0, 12),
        )
        ->and($composer['repositories'][1]['type'])->toBe('package')
        ->and($composer['repositories'][1]['package']['dist']['url'])
        ->toBe('https://plugins.example.test/sample-plugin-1.2.3.zip')
        ->and($composer['extra']['installer-paths'])->toHaveKey('wordpress/wp-content/plugins/sample-plugin/')
        ->and($composer['extra']['installer-paths'])->toHaveKey('wordpress/wp-content/plugins/{$name}/')
        ->and($composer['extra']['installer-paths'])->toHaveKey('wordpress/wp-content/themes/{$name}/')
        ->and($composer['config']['allow-plugins']['composer/installers'])->toBeTrue()
        ->and($composer['config']['allow-plugins']['roots/wordpress-core-installer'])->toBeTrue()
        ->and($process->commands)->toBe([])
        ->and($contentPath.'/db.php')->toBeFile()
        ->and($fixturePath.'/wordpress/wp-config.php')->toBeFile()
        ->and(file_get_contents($fixturePath.'/wordpress/wp-config.php'))
        ->toContain(
            "define('DB_FILE', 'wordpress.sqlite');",
            "define('WP_DEFAULT_THEME', 'twentytwentyfive');",
            "define('WP_INSTALLING', true);",
        );
});

it('flattens plugin archives with a redundant inner plugin directory', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
    'plugins' => [
        'dependencies' => [[
            'slug' => 'sample-plugin',
            'version' => '1.2.3',
            'url' => 'https://plugins.example.test/sample-plugin-1.2.3.zip',
        ]],
    ],
];
PHP);

    $fixturePath = $this->workspace.'/.wp-pest/fixtures/default--wp-7.0';
    $wordpressPath = $fixturePath.'/wordpress';
    $contentPath = $wordpressPath.'/wp-content';
    mkdir($contentPath.'/plugins/sqlite-database-integration', 0775, true);
    mkdir($contentPath.'/plugins/sample-plugin/sample-plugin', 0775, true);
    file_put_contents($wordpressPath.'/wp-load.php', '<?php');
    file_put_contents($contentPath.'/plugins/sqlite-database-integration/db.copy', '<?php');
    file_put_contents($contentPath.'/plugins/sample-plugin/sample-plugin/sample-plugin.php', '<?php');
    file_put_contents($contentPath.'/plugins/sample-plugin/sample-plugin/includes.php', '<?php');

    $config = (new ConfigLoader)->load($this->workspace);
    (new PluginFixture($config, $this->workspace, new FixtureRecordingProcess))->ensure();

    expect($contentPath.'/plugins/sample-plugin/sample-plugin.php')->toBeFile()
        ->and($contentPath.'/plugins/sample-plugin/includes.php')->toBeFile()
        ->and(is_dir($contentPath.'/plugins/sample-plugin/sample-plugin'))->toBeFalse();
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

    public function capture(array $command, ?string $cwd = null): string
    {
        $this->commands[] = $command;

        return '';
    }
}
