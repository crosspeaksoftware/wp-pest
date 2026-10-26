<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Matrix\MatrixCombination;
use CrossPeak\WpPest\Runtime\Constants;
use CrossPeak\WpPest\Support\Process;
use CrossPeak\WpPest\Support\ProcessRunner;
use RuntimeException;

/**
 * Runs a browser sandbox directly in the current PHP process environment.
 *
 * This intentionally uses the SQLite fixture prepared by PluginFixture. It
 * provides a fast local loop, while the Docker driver remains responsible for
 * testing alternate PHP and database combinations.
 */
final class LocalSandboxManager
{
    /** @var array<string, resource> */
    private array $servers = [];

    /** @var array<string, string> */
    private array $wordpressPaths = [];

    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly ProcessRunner $process = new Process,
    ) {}

    public function writeCompose(string $name): string
    {
        $directory = $this->sandboxDirectory($name);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'local.json';
        file_put_contents($path, json_encode([
            'driver' => 'local',
            'matrix' => $name,
            'php' => PHP_VERSION,
            'database' => 'sqlite',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

        return $path;
    }

    public function start(string $name, bool $fresh = false): int
    {
        $entry = $this->entry($name);
        $fixture = (new PluginFixture($this->config, $this->workingDirectory))->ensure();
        $wordpress = $this->runtimeWordpress($name, $fixture.DIRECTORY_SEPARATOR.'wordpress');
        $this->wordpressPaths[$name] = $wordpress;
        $this->configureRuntime($name, $entry, $wordpress, $fresh);

        if (! $this->isInstalled($wordpress)) {
            $url = $this->url($name);
            $exitCode = $this->process->run([
                'wp',
                '--no-color',
                '--path='.$wordpress,
                'core',
                'install',
                '--url='.$url,
                '--title=WP Pest',
                '--admin_user=admin',
                '--admin_password=password',
                '--admin_email=admin@example.test',
                '--skip-email',
            ], $this->workingDirectory, true);
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        foreach ($entry->plugins() as $index => $dependency) {
            if ($dependency->isLocalPath()) {
                $this->copyPlugin(
                    $wordpress.DIRECTORY_SEPARATOR.'wp-content/plugins/dependency-'.$index,
                    $this->absolutePath($dependency->source()),
                );
                $slug = 'dependency-'.$index;
            } else {
                $slug = $dependency->slug();
            }

            $exitCode = $this->process->run([
                'wp',
                '--no-color',
                '--path='.$wordpress,
                'plugin',
                'activate',
                $slug,
            ], $this->workingDirectory, true);
            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        $exitCode = $this->process->run([
            'wp',
            '--no-color',
            '--path='.$wordpress,
            'plugin',
            'activate',
            'plugin-under-test',
        ], $this->workingDirectory, true);
        if ($exitCode !== 0) {
            return $exitCode;
        }

        $port = $this->port($entry);
        $router = $this->writeRouter($name, $wordpress);
        $log = $this->sandboxDirectory($name).DIRECTORY_SEPARATOR.'server.log';
        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            '-t',
            $wordpress,
            $router,
        ];
        $server = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            $wordpress,
        );

        if (! is_resource($server)) {
            throw new RuntimeException('Unable to start the local WordPress server.');
        }

        $this->servers[$name] = $server;
        if (! $this->waitForServer($port)) {
            $this->stop($name);
            throw new RuntimeException("The local WordPress server did not start; see {$log}.");
        }

        return 0;
    }

    public function stop(string $name): int
    {
        $server = $this->servers[$name] ?? null;
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
            unset($this->servers[$name]);
        }

        return 0;
    }

    public function reset(string $name): int
    {
        $this->stop($name);

        return $this->start($name, fresh: true);
    }

    public function destroy(string $name): int
    {
        $this->stop($name);

        return 0;
    }

    public function url(string $name): string
    {
        return 'http://127.0.0.1:'.$this->port($this->entry($name));
    }

    /** @param list<string> $arguments */
    public function wpCli(string $name, array $arguments): string
    {
        $fixture = (new PluginFixture($this->config, $this->workingDirectory))->pathFor($name);
        $wordpress = $this->wordpressPaths[$name] ?? $this->runtimeWordpress($name, $fixture.DIRECTORY_SEPARATOR.'wordpress');

        return $this->process->capture([
            'wp',
            '--no-color',
            '--path='.$wordpress,
            ...$arguments,
        ], $this->workingDirectory);
    }

    private function configureRuntime(string $name, MatrixCombination $entry, string $wordpress, bool $fresh): void
    {
        $directory = $this->sandboxDirectory($name);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $pluginPath = $this->config->pluginPath();
        if ($pluginPath !== null) {
            $this->copyPlugin(
                $wordpress.DIRECTORY_SEPARATOR.'wp-content/plugins/plugin-under-test',
                $this->absolutePath($pluginPath),
            );
        }

        $this->writeSqliteCompatibility($wordpress);

        if ($fresh) {
            $database = $wordpress.DIRECTORY_SEPARATOR.'.wp-pest'.DIRECTORY_SEPARATOR.'wordpress.sqlite';
            if (is_file($database)) {
                unlink($database);
            }

            $uploads = $wordpress.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'uploads';
            if (is_dir($uploads)) {
                $this->removeDirectory($uploads);
            }
        }

        $this->configureUrl($wordpress, $this->url($name), $entry->env());
    }

    private function isInstalled(string $wordpress): bool
    {
        try {
            $this->process->capture(['wp', '--no-color', '--path='.$wordpress, 'core', 'is-installed'], $this->workingDirectory);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @param array<string, string> $environment */
    private function configureUrl(string $wordpress, string $url, array $environment = []): void
    {
        $config = $wordpress.DIRECTORY_SEPARATOR.'wp-config.php';
        if (file_exists($config) || is_link($config)) {
            unlink($config);
        }

        $databaseDirectory = $wordpress.DIRECTORY_SEPARATOR.'.wp-pest';
        $extra = Constants::code($this->config->wordpressConstants())."\n".($environment['WORDPRESS_CONFIG_EXTRA'] ?? '');
        $contents = "<?php\n\ndefine('DB_NAME', 'wp_pest');\ndefine('DB_USER', 'wp_pest');\ndefine('DB_PASSWORD', 'wp_pest');\ndefine('DB_HOST', 'localhost');\ndefine('DB_CHARSET', 'utf8');\ndefine('DB_COLLATE', '');\ndefine('DB_DIR', ".var_export($databaseDirectory, true).");\ndefine('DB_FILE', 'wordpress.sqlite');\ndefine('WP_HOME', ".var_export($url, true).");\ndefine('WP_SITEURL', ".var_export($url, true).");\ndefine('WP_DEFAULT_THEME', 'twentytwentyfive');\ndefine('WP_DEBUG', true);\n".$extra."\n\$table_prefix = 'wp_';\nif (! defined('ABSPATH')) { define('ABSPATH', __DIR__.DIRECTORY_SEPARATOR); }\nrequire_once ABSPATH.'wp-settings.php';\n";
        file_put_contents($config, $contents);
    }

    private function writeSqliteCompatibility(string $wordpress): void
    {
        $directory = $wordpress.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'mu-plugins';
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'wp-pest-sqlite-compat.php';
        $contents = <<<'PHP'
<?php

// Gravity Forms uses MySQL's upsert syntax for entry metadata. SQLite's
// translator exposes this filter so the local browser sandbox can translate
// that operation without changing the plugin under test.
add_filter('pre_query_sqlite_db', static function ($result, $translator, $statement) {
    if (! is_string($statement) || ! str_contains(strtolower($statement), 'on duplicate key update') || ! str_contains(strtolower($statement), 'gf_entry_meta')) {
        return $result;
    }

    $statement = (string) preg_replace(
        '/on duplicate key update\s+meta_value\s*=\s*values\s*\(meta_value\)\s*;?\s*$/i',
        'ON CONFLICT(id) DO UPDATE SET meta_value=excluded.meta_value',
        trim($statement),
    );
    $statement = (string) preg_replace('/(INSERT INTO\s+[^\s]+\s*\([^)]*\bid\b[^)]*\)\s*VALUES\s*)\(\s*\'\'\s*,/i', '$1(NULL,', $statement);

    return $translator->execute_sqlite_query($statement);
}, 10, 5);
PHP;
        file_put_contents($path, $contents.PHP_EOL);
    }

    private function copyPlugin(string $destination, string $source): void
    {
        if (! is_dir($source)) {
            throw new ValidationException(["Plugin path does not exist: {$source}."]);
        }

        if (is_link($destination) || is_file($destination)) {
            unlink($destination);
        } elseif (is_dir($destination)) {
            $this->removeDirectory($destination);
        }

        mkdir($destination, 0775, true);
        $this->copyDirectory($source, $destination, skipConfig: false);
    }

    private function writeRouter(string $name, string $wordpress): string
    {
        $path = $this->sandboxDirectory($name).DIRECTORY_SEPARATOR.'router.php';
        $documentRoot = var_export($wordpress, true);
        file_put_contents($path, "<?php\n\n\$documentRoot = {$documentRoot};\n\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n\$file = rtrim(\$documentRoot, DIRECTORY_SEPARATOR).\$path;\nif (\$path !== '/' && is_file(\$file)) { return false; }\nif (is_dir(\$file) && is_file(rtrim(\$file, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'index.php')) { require rtrim(\$file, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'index.php'; return; }\nrequire rtrim(\$documentRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'index.php';\n");

        return $path;
    }

    private function waitForServer(int $port): bool
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $error, $message, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function entry(string $name): MatrixCombination
    {
        foreach ($this->config->matrixCombinations() as $entry) {
            if ($entry->name() === $name) {
                return $entry;
            }
        }

        throw new ValidationException(["Unknown matrix combination: {$name}."]);
    }

    private function port(MatrixCombination $entry): int
    {
        $port = $entry->port();

        return $port > 0 ? $port : 8080 + abs(crc32($entry->name())) % 1000;
    }

    private function sandboxDirectory(string $name): string
    {
        $base = (string) $this->config->get('sandbox.path', '.wp-pest/sandboxes');

        return $this->workingDirectory.DIRECTORY_SEPARATOR.trim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    private function runtimeWordpress(string $name, string $source): string
    {
        $runtime = $this->sandboxDirectory($name).DIRECTORY_SEPARATOR.'wordpress';
        if (is_dir($runtime)) {
            return $runtime;
        }

        if (! is_dir($source)) {
            throw new ValidationException(["WordPress fixture does not exist: {$source}."]);
        }

        mkdir($runtime, 0775, true);
        $this->copyDirectory($source, $runtime);

        return $runtime;
    }

    private function copyDirectory(string $source, string $destination, bool $skipConfig = true): void
    {
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..' || ($skipConfig && $item === 'wp-config.php') || (! $skipConfig && $item === '.wp-pest')) {
                continue;
            }

            $sourcePath = $source.DIRECTORY_SEPARATOR.$item;
            $destinationPath = $destination.DIRECTORY_SEPARATOR.$item;

            if (is_link($sourcePath)) {
                symlink((string) readlink($sourcePath), $destinationPath);
            } elseif (is_dir($sourcePath)) {
                mkdir($destinationPath, 0775, true);
                $this->copyDirectory($sourcePath, $destinationPath);
            } else {
                copy($sourcePath, $destinationPath);
            }
        }
    }
}
