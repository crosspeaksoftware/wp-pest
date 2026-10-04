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
    'wordpress' => [
        'options' => ['sample_option' => 'configured'],
    ],
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
    mkdir($contentPath.'/plugins/akismet', 0775, true);
    mkdir($contentPath.'/plugins/sample-plugin', 0775, true);
    file_put_contents($fixturePath.'/wordpress/wp-load.php', '<?php');
    file_put_contents($contentPath.'/plugins/sqlite-database-integration/db.copy', '<?php // SQLite drop-in');
    file_put_contents($contentPath.'/plugins/akismet/akismet.php', "<?php\n/* Plugin Name: Akismet */");
    file_put_contents($contentPath.'/plugins/sample-plugin/sample-plugin.php', "<?php\n/* Plugin Name: Sample */");
    file_put_contents($this->workspace.'/plugin.php', "<?php\n/* Plugin Name: Under Test */");

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
        ->and($process->commands)->toBe([
            [PHP_BINARY, $fixturePath.'/prepare.php'],
            [PHP_BINARY, $fixturePath.'/finalize.php'],
            [PHP_BINARY, $fixturePath.'/configure.php'],
        ])
        ->and($contentPath.'/db.php')->toBeFile()
        ->and($fixturePath.'/wordpress/wp-config.php')->toBeFile()
        ->and($fixturePath.'/prepare.php')->toBeFile()
        ->and($fixturePath.'/finalize.php')->toBeFile()
        ->and($fixturePath.'/configure.php')->toBeFile()
        ->and($contentPath.'/mu-plugins/wp-pest-options.php')->toBeFile()
        ->and(is_link($contentPath.'/plugins/plugin-under-test'))->toBeTrue()
        ->and(file_get_contents($fixturePath.'/prepare.php'))->toContain(
            "'akismet/akismet.php'",
            "'sample-plugin/sample-plugin.php'",
            "'plugin-under-test/plugin.php'",
            "activate_plugin(\$plugin, '', false, false)",
            "'sample_option' => 'configured'",
            'update_option($option, $value)',
        )
        ->and(file_get_contents($fixturePath.'/finalize.php'))->toContain("require '")
        ->and(file_get_contents($contentPath.'/mu-plugins/wp-pest-options.php'))->toContain(
            "'sample_option' => 'configured'",
            "add_filter('pre_option_'.\$option, \$filter)",
        )
        ->and(file_get_contents($fixturePath.'/wordpress/wp-config.php'))
        ->toContain(
            "define('DB_FILE', 'wordpress.sqlite');",
            "define('WP_DEFAULT_THEME', 'twentytwentyfive');",
            "define('SQLITE_JOURNAL_MODE', 'MEMORY');",
            "define('WP_INSTALLING', getenv('WP_PEST_INSTALLING') === '1');",
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

it('prepares a clean database when the plugin source changes at the same plugin filename', function (): void {
    $configFile = $this->workspace.'/wp-pest.php';
    file_put_contents($configFile, "<?php return ['target' => 'plugin', 'tests' => ['code'], 'plugin' => ['path' => 'plugin-a']];");
    foreach (['plugin-a', 'plugin-b'] as $plugin) {
        mkdir($this->workspace.'/'.$plugin);
        file_put_contents($this->workspace.'/'.$plugin.'/plugin.php', "<?php\n/* Plugin Name: {$plugin} */");
    }

    $fixturePath = $this->workspace.'/.wp-pest/fixtures/default--wp-7.0';
    $wordpressPath = $fixturePath.'/wordpress';
    $contentPath = $wordpressPath.'/wp-content';
    mkdir($contentPath.'/plugins/sqlite-database-integration', 0775, true);
    file_put_contents($wordpressPath.'/wp-load.php', '<?php');
    file_put_contents($contentPath.'/plugins/sqlite-database-integration/db.copy', '<?php');

    $process = new FixtureRecordingProcess;
    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();
    $database = $wordpressPath.'/.wp-pest/wordpress.sqlite';
    file_put_contents($database, 'old database');

    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();
    expect($process->commands)->toHaveCount(3)
        ->and($database)->toBeFile();

    file_put_contents($configFile, "<?php return ['target' => 'plugin', 'tests' => ['code'], 'plugin' => ['path' => 'plugin-a'], 'wordpress' => ['options' => ['sample' => 'updated']]];");
    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();
    expect($process->commands)->toHaveCount(6)
        ->and($database)->toBeFile();

    file_put_contents($configFile, "<?php return ['target' => 'plugin', 'tests' => ['code'], 'plugin' => ['path' => 'plugin-b']];");
    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();

    expect($process->commands)->toHaveCount(9)
        ->and($database)->not->toBeFile()
        ->and(realpath($contentPath.'/plugins/plugin-under-test'))->toBe(realpath($this->workspace.'/plugin-b'));

    file_put_contents($database, 'old database');
    file_put_contents($this->workspace.'/plugin-b/plugin.php', "<?php\n/* Plugin Name: plugin-b */\n// Changed activation code.");
    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();

    expect($process->commands)->toHaveCount(12)
        ->and($database)->not->toBeFile();

    file_put_contents($database, 'old database');
    file_put_contents($fixturePath.'/composer.lock', '{"packages":[{"name":"roots/wordpress","version":"7.0.1"}]}');
    (new PluginFixture((new ConfigLoader)->load($this->workspace), $this->workspace, $process))->ensure();

    expect($process->commands)->toHaveCount(15)
        ->and($database)->not->toBeFile();
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
