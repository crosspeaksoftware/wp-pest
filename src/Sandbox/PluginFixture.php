<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Support\Process;
use CrossPeak\WpPest\Support\ProcessRunner;

final class PluginFixture
{
    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly ProcessRunner $process = new Process,
    ) {}

    public function path(): string
    {
        $name = getenv('WP_PEST_MATRIX');
        $name = is_string($name) && $name !== ''
            ? $name
            : ($this->config->activeMatrix()?->name() ?? 'default');

        return $this->pathFor($name);
    }

    public function pathFor(string $name): string
    {
        // Keep WordPress outside every plugin source tree: an ancestor symlink
        // otherwise makes plugin_basename() rewrite dependency paths as children
        // of the plugin under test. The project hash keeps caches independent.
        $project = realpath($this->workingDirectory) ?: $this->workingDirectory;
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wp-pest-'.hash('sha256', $project);

        return $root.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$name;
    }

    public function ensure(): string
    {
        $path = $this->path();

        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }

        $composerJson = $path.DIRECTORY_SEPARATOR.'composer.json';
        $repositories = [
            [
                'type' => 'composer',
                'url' => 'https://wpackagist.org',
            ],
        ];
        $requirements = [
            'roots/wordpress' => $this->config->wordpressVersion(),
            'wpackagist-plugin/sqlite-database-integration' => '^2.2',
            'wpackagist-theme/twentytwentyfive' => '*',
        ];
        $installerPaths = [
            'wordpress/wp-content/themes/{$name}/' => ['type:wordpress-theme'],
        ];

        foreach ($this->config->dependencyPlugins() as $dependency) {
            if ($dependency->isLocalPath()) {
                continue;
            }

            $package = $dependency->packageName();
            $requirements[$package] = $dependency->version();
            $installerPaths['wordpress/wp-content/plugins/'.$dependency->slug().'/'] = [$package];

            if ($dependency->isUrl()) {
                $repositories[] = [
                    'type' => 'package',
                    'package' => [
                        'name' => $package,
                        'version' => $dependency->version(),
                        'type' => 'wordpress-plugin',
                        'dist' => [
                            'url' => $dependency->source(),
                            'type' => 'zip',
                        ],
                    ],
                ];
            }
        }

        $installerPaths['wordpress/wp-content/plugins/{$name}/'] = ['type:wordpress-plugin'];
        $composerContents = json_encode([
            'repositories' => $repositories,
            'require' => $requirements,
            'extra' => [
                'wordpress-install-dir' => 'wordpress',
                'installer-paths' => $installerPaths,
            ],
            'config' => [
                'allow-plugins' => [
                    'composer/installers' => true,
                    'roots/wordpress-core-installer' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $composerChanged = ! is_file($composerJson) || file_get_contents($composerJson) !== $composerContents;

        if ($composerChanged) {
            file_put_contents($composerJson, $composerContents);
        }

        $wordpressPath = $path.DIRECTORY_SEPARATOR.'wordpress';
        $sqlitePath = $wordpressPath.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'sqlite-database-integration'.DIRECTORY_SEPARATOR.'db.copy';

        $exitCode = 0;
        if ($composerChanged && is_file($path.DIRECTORY_SEPARATOR.'composer.lock')) {
            $exitCode = $this->process->run(['composer', 'update', '--no-interaction'], $path, true);
        } elseif (! is_file($wordpressPath.DIRECTORY_SEPARATOR.'wp-load.php') || ! is_file($sqlitePath)) {
            $exitCode = $this->process->run(['composer', 'install'], $path, true);
        }

        if ($exitCode !== 0) {
            throw new ValidationException(["Composer could not install WordPress fixture {$path}."]);
        }

        $this->normalizePluginDirectories($wordpressPath);
        $this->configureWordPress($wordpressPath);
        $this->configureWordpressOptions($wordpressPath);
        $pluginFiles = $this->configurePluginDirectories($wordpressPath);
        $this->prepareWordPress($path, $wordpressPath, $pluginFiles, hash('sha256', $composerContents));

        return $path;
    }

    public function resolvedWordpressVersion(): ?string
    {
        $lock = $this->path().DIRECTORY_SEPARATOR.'composer.lock';
        if (! is_file($lock)) {
            return null;
        }

        $contents = json_decode((string) file_get_contents($lock), true);
        foreach ($contents['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === 'roots/wordpress') {
                return isset($package['version']) ? ltrim((string) $package['version'], 'v') : null;
            }
        }

        return null;
    }

    private function configureWordPress(string $wordpressPath): void
    {
        $contentPath = $wordpressPath.DIRECTORY_SEPARATOR.'wp-content';
        $sqlitePath = $contentPath.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'sqlite-database-integration';
        $dropIn = $contentPath.DIRECTORY_SEPARATOR.'db.php';

        if (! is_file($dropIn) && is_file($sqlitePath.DIRECTORY_SEPARATOR.'db.copy')) {
            copy($sqlitePath.DIRECTORY_SEPARATOR.'db.copy', $dropIn);
        }

        $config = $wordpressPath.DIRECTORY_SEPARATOR.'wp-config.php';
        if (is_file($config) && filetype($config) === 'file') {
            $contents = (string) file_get_contents($config);
            if (str_contains($contents, "define('WP_DEFAULT_THEME'")) {
                $contents = (string) preg_replace(
                    "/define\\('WP_DEFAULT_THEME',\\s*'[^']+'\\);/",
                    "define('WP_DEFAULT_THEME', 'twentytwentyfive');",
                    $contents,
                );
            } else {
                $contents = str_replace(
                    "define('WP_DEBUG', true);",
                    "define('WP_DEFAULT_THEME', 'twentytwentyfive');\ndefine('WP_DEBUG', true);",
                    $contents,
                );
            }

            $installing = "define('WP_INSTALLING', getenv('WP_PEST_INSTALLING') === '1');";
            if (str_contains($contents, "define('WP_INSTALLING'")) {
                $contents = (string) preg_replace("/define\\('WP_INSTALLING',\\s*[^;]+;/", $installing, $contents);
            } else {
                $contents = str_replace("define('WP_DEBUG', true);", "define('WP_DEBUG', true);\n{$installing}", $contents);
            }

            $journal = "define('SQLITE_JOURNAL_MODE', '".$this->config->sqliteJournalMode()."');";
            if (str_contains($contents, "define('SQLITE_JOURNAL_MODE'")) {
                $contents = (string) preg_replace("/define\\('SQLITE_JOURNAL_MODE',\\s*'[^']+'\\);/", $journal, $contents);
            } else {
                $contents = str_replace("define('WP_DEBUG', true);", "define('WP_DEBUG', true);\n{$journal}", $contents);
            }

            file_put_contents($config, $contents);

            return;
        }

        if (file_exists($config) || is_link($config)) {
            // A stale fixture may contain a mounted device at this path. The
            // local sandbox copies fixtures and creates its own config, so do
            // not attempt to mutate an unusable cached fixture in place.
            if (filetype($config) !== 'file') {
                return;
            }

            unlink($config);
        }

        $databasePath = $wordpressPath.DIRECTORY_SEPARATOR.'.wp-pest';
        $contents = <<<'PHP'
<?php

define('DB_NAME', 'wp_pest');
define('DB_USER', 'wp_pest');
define('DB_PASSWORD', 'wp_pest');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
define('DB_DIR', __DIR__.'/.wp-pest');
define('DB_FILE', 'wordpress.sqlite');
define('WP_HOME', 'http://wp-pest.test');
define('WP_SITEURL', 'http://wp-pest.test');
define('WP_DEFAULT_THEME', 'twentytwentyfive');
define('WP_DEBUG', true);
define('SQLITE_JOURNAL_MODE', 'JOURNAL_MODE_PLACEHOLDER');
define('WP_INSTALLING', getenv('WP_PEST_INSTALLING') === '1');

$table_prefix = 'wp_';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__.DIRECTORY_SEPARATOR);
}

require_once ABSPATH.'wp-settings.php';
PHP;

        $contents = str_replace('JOURNAL_MODE_PLACEHOLDER', $this->config->sqliteJournalMode(), $contents);

        if (! is_dir($databasePath)) {
            mkdir($databasePath, 0775, true);
        }

        file_put_contents($config, $contents.PHP_EOL);
    }

    /**
     * Make local plugins available inside WordPress and return their plugin files in load order.
     *
     * @return list<string>
     */
    private function configurePluginDirectories(string $wordpressPath): array
    {
        $pluginsPath = $wordpressPath.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'plugins';
        $pluginFiles = [];

        foreach ($this->config->dependencyPlugins() as $dependency) {
            $directory = $pluginsPath.DIRECTORY_SEPARATOR.$dependency->slug();
            if ($dependency->isLocalPath()) {
                $source = $this->absolutePath($dependency->source());
                $this->linkPlugin($source, $directory);
            }

            $file = $this->pluginFile($directory);
            if ($file !== null) {
                $pluginFiles[] = $dependency->slug().'/'.basename($file);
            }
        }

        $pluginPath = $this->config->pluginPath();
        if ($pluginPath !== null) {
            $directory = $pluginsPath.DIRECTORY_SEPARATOR.'plugin-under-test';
            $this->linkPlugin($this->absolutePath($pluginPath), $directory);
            $file = $this->pluginFile($directory);
            if ($file !== null) {
                $pluginFiles[] = 'plugin-under-test/'.basename($file);
            }
        }

        return $pluginFiles;
    }

    private function configureWordpressOptions(string $wordpressPath): void
    {
        $directory = $wordpressPath.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'mu-plugins';
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $options = var_export($this->config->wordpressOptions(), true);
        $contents = <<<PHP
<?php

\$GLOBALS['wp_pest_option_filters'] = [];

foreach ({$options} as \$option => \$value) {
    \$filter = static fn (): mixed => \$value;
    \$GLOBALS['wp_pest_option_filters'][\$option] = \$filter;
    add_filter('pre_option_'.\$option, \$filter);
}
PHP;

        file_put_contents($directory.DIRECTORY_SEPARATOR.'wp-pest-options.php', $contents.PHP_EOL);
    }

    /**
     * Install WordPress and activate plugins in a separate process so the test process
     * loads every plugin through WordPress's normal plugins_loaded/init lifecycle.
     *
     * @param  list<string>  $pluginFiles
     */
    private function prepareWordPress(string $fixturePath, string $wordpressPath, array $pluginFiles, string $composerHash): void
    {
        $localSources = [];
        foreach ($this->config->dependencyPlugins() as $dependency) {
            if ($dependency->isLocalPath()) {
                $localSources[$dependency->slug()] = $this->localSourceFingerprint($this->absolutePath($dependency->source()));
            }
        }
        $localSources['plugin-under-test'] = $this->localSourceFingerprint($this->absolutePath($this->config->pluginPath() ?? '.'));
        $lockFile = $fixturePath.DIRECTORY_SEPARATOR.'composer.lock';
        $installationState = hash('sha256', json_encode([
            'plugins' => $pluginFiles,
            'local_sources' => $localSources,
            'activate' => $this->config->activatePlugins(),
            'context' => $this->config->wordpressContext(),
            'composer' => $composerHash,
            'composer_lock' => is_file($lockFile) ? hash_file('sha256', $lockFile) : null,
        ], JSON_THROW_ON_ERROR));
        $state = hash('sha256', json_encode([
            'installation' => $installationState,
            'options' => $this->config->wordpressOptions(),
        ], JSON_THROW_ON_ERROR));
        $stateFile = $fixturePath.DIRECTORY_SEPARATOR.'.prepared';
        $installationStateFile = $fixturePath.DIRECTORY_SEPARATOR.'.prepared-plugins';
        $database = $wordpressPath.DIRECTORY_SEPARATOR.'.wp-pest'.DIRECTORY_SEPARATOR.'wordpress.sqlite';

        if (is_file($database)
            && is_file($stateFile)
            && trim((string) file_get_contents($stateFile)) === $state
            && is_file($installationStateFile)
            && trim((string) file_get_contents($installationStateFile)) === $installationState) {
            return;
        }

        if (is_file($database) && (! is_file($installationStateFile)
            || trim((string) file_get_contents($installationStateFile)) !== $installationState)) {
            // Activation hooks must run against a clean database when a plugin
            // source changes, even if its plugin filename is unchanged.
            foreach ([$database, $database.'-journal', $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $script = $fixturePath.DIRECTORY_SEPARATOR.'prepare.php';
        $wpLoad = var_export($wordpressPath.DIRECTORY_SEPARATOR.'wp-load.php', true);
        $plugins = var_export($pluginFiles, true);
        $options = var_export($this->config->wordpressOptions(), true);
        $activate = $this->config->activatePlugins() ? 'true' : 'false';
        $context = $this->config->wordpressContext() === 'admin' ? "define('WP_ADMIN', true);" : '';
        $contents = <<<PHP
<?php

putenv('WP_PEST_INSTALLING=1');
{$context}
require {$wpLoad};

require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

if (! is_blog_installed()) {
    \$skipMail = static fn (): bool => true;
    add_filter('pre_wp_mail', \$skipMail);
    wp_install('WP Pest', 'admin', 'admin@example.test', true, '', 'password');
    remove_filter('pre_wp_mail', \$skipMail);
}

wp_installing(false);
global \$wpdb;
\$wpdb->suppress_errors(true);
\$plugins = {$plugins};

if ({$activate}) {
    update_option('active_plugins', array_values(array_intersect(
        (array) get_option('active_plugins', []),
        \$plugins,
    )));
    add_filter('doing_it_wrong_trigger_error', '__return_false');

    foreach (\$plugins as \$plugin) {
        if (is_plugin_active(\$plugin)) {
            continue;
        }

        \$result = activate_plugin(\$plugin, '', false, false);
        if (is_wp_error(\$result)) {
            fwrite(STDERR, \$result->get_error_message().PHP_EOL);
            exit(1);
        }
    }

    remove_filter('doing_it_wrong_trigger_error', '__return_false');
} else {
    update_option('active_plugins', array_values(array_unique(array_merge(
        (array) get_option('active_plugins', []),
        \$plugins,
    ))));
}

foreach (\$GLOBALS['wp_pest_option_filters'] ?? [] as \$option => \$filter) {
    remove_filter('pre_option_'.\$option, \$filter);
}

foreach ({$options} as \$option => \$value) {
    wp_cache_delete(\$option, 'options');
    update_option(\$option, \$value);
}
PHP;
        file_put_contents($script, $contents.PHP_EOL);

        if ($this->process->run([PHP_BINARY, $script], $fixturePath, true) !== 0) {
            throw new ValidationException(["Could not prepare WordPress fixture {$fixturePath}."]);
        }

        $finalizeScript = $fixturePath.DIRECTORY_SEPARATOR.'finalize.php';
        $finalizeContents = <<<PHP
<?php

define('WP_DEBUG_DISPLAY', false);
{$context}
require {$wpLoad};
PHP;
        file_put_contents($finalizeScript, $finalizeContents.PHP_EOL);

        if ($this->process->run([PHP_BINARY, $finalizeScript], $fixturePath, true) !== 0) {
            throw new ValidationException(["Could not finalize WordPress fixture {$fixturePath}."]);
        }

        // Re-run the installer-mode script after the normal request so deferred
        // plugin migrations cannot overwrite explicitly configured options.
        $configureScript = $fixturePath.DIRECTORY_SEPARATOR.'configure.php';
        file_put_contents($configureScript, $contents.PHP_EOL);
        if ($this->process->run([PHP_BINARY, $configureScript], $fixturePath, true) !== 0) {
            throw new ValidationException(["Could not configure WordPress fixture {$fixturePath}."]);
        }

        file_put_contents($stateFile, $state.PHP_EOL);
        file_put_contents($installationStateFile, $installationState.PHP_EOL);
    }

    private function localSourceFingerprint(string $path): string
    {
        $root = realpath($path);
        if ($root === false) {
            throw new ValidationException(["Plugin path does not exist: {$path}."]);
        }

        $ignoredDirectories = ['.git', '.wp-pest', 'node_modules', 'tests', 'vendor'];
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static fn (\SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), $ignoredDirectories, true)),
        );
        $files = [];
        foreach (new \RecursiveIteratorIterator($filtered) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[$relative] = $file->isFile()
                ? hash_file('sha256', $file->getPathname())
                : ($file->isLink() ? readlink($file->getPathname()) : null);
        }
        ksort($files);

        return hash('sha256', $root.json_encode($files, JSON_THROW_ON_ERROR));
    }

    private function linkPlugin(string $source, string $destination): void
    {
        if (! is_dir($source)) {
            throw new ValidationException(["Plugin path does not exist: {$source}."]);
        }

        if (is_link($destination)) {
            if (realpath($destination) === realpath($source)) {
                return;
            }
            unlink($destination);
        } elseif (file_exists($destination)) {
            throw new ValidationException(["Plugin fixture path already exists: {$destination}."]);
        }

        if (! symlink($source, $destination)) {
            throw new ValidationException(["Could not link plugin {$source} into the WordPress fixture."]);
        }
    }

    private function pluginFile(string $directory): ?string
    {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file, false, null, 0, 8192);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $contents) === 1) {
                return $file;
            }
        }

        return null;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }

    /**
     * Some premium plugin ZIPs contain a redundant plugin-named directory
     * inside the archive. Composer already installs the package into that
     * directory, so flatten this common packaging mistake for WordPress.
     */
    private function normalizePluginDirectories(string $wordpressPath): void
    {
        $pluginsPath = $wordpressPath.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'plugins';

        foreach ($this->config->dependencyPlugins() as $dependency) {
            if ($dependency->isLocalPath()) {
                continue;
            }

            $pluginPath = $pluginsPath.DIRECTORY_SEPARATOR.$dependency->slug();
            if (! is_dir($pluginPath) || glob($pluginPath.DIRECTORY_SEPARATOR.'*.php') !== []) {
                continue;
            }

            $nestedPluginPaths = array_values(array_filter(
                glob($pluginPath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
                static fn (string $directory): bool => glob($directory.DIRECTORY_SEPARATOR.'*.php') !== [],
            ));

            if (count($nestedPluginPaths) !== 1) {
                continue;
            }

            $nestedPluginPath = $nestedPluginPaths[0];
            foreach (scandir($nestedPluginPath) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $source = $nestedPluginPath.DIRECTORY_SEPARATOR.$item;
                $destination = $pluginPath.DIRECTORY_SEPARATOR.$item;
                if (file_exists($destination) || ! rename($source, $destination)) {
                    throw new ValidationException(["Could not normalize the plugin directory {$pluginPath}."]);
                }
            }

            if (! rmdir($nestedPluginPath)) {
                throw new ValidationException(["Could not remove the redundant plugin directory {$nestedPluginPath}."]);
            }
        }
    }
}
