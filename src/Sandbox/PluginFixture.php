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

        return $this->workingDirectory.DIRECTORY_SEPARATOR.'.wp-pest'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$name;
    }

    public function ensure(): string
    {
        $path = $this->path();

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
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
        ];
        $installerPaths = [];

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

        $this->configureWordPress($wordpressPath);

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

        $this->configureDefaultTheme($contentPath);

        if (! is_file($dropIn) && is_file($sqlitePath.DIRECTORY_SEPARATOR.'db.copy')) {
            copy($sqlitePath.DIRECTORY_SEPARATOR.'db.copy', $dropIn);
        }

        $config = $wordpressPath.DIRECTORY_SEPARATOR.'wp-config.php';
        if (is_file($config)) {
            $contents = (string) file_get_contents($config);
            if (! str_contains($contents, "define('WP_DEFAULT_THEME'")) {
                $contents = str_replace(
                    "define('WP_DEBUG', true);",
                    "define('WP_DEFAULT_THEME', 'wp-pest');\ndefine('WP_DEBUG', true);",
                    $contents,
                );
                file_put_contents($config, $contents);
            }

            return;
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
define('WP_DEFAULT_THEME', 'wp-pest');
define('WP_DEBUG', true);
define('WP_INSTALLING', true);

$table_prefix = 'wp_';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__.DIRECTORY_SEPARATOR);
}

require_once ABSPATH.'wp-settings.php';
PHP;

        if (! is_dir($databasePath)) {
            mkdir($databasePath, 0775, true);
        }

        file_put_contents($config, $contents.PHP_EOL);
    }

    private function configureDefaultTheme(string $contentPath): void
    {
        $themePath = $contentPath.DIRECTORY_SEPARATOR.'themes'.DIRECTORY_SEPARATOR.'wp-pest';
        if (! is_dir($themePath)) {
            mkdir($themePath, 0775, true);
        }

        $stylesheet = $themePath.DIRECTORY_SEPARATOR.'style.css';
        if (! is_file($stylesheet)) {
            file_put_contents($stylesheet, <<<'CSS'
/*
Theme Name: WP Pest
Version: 1.0.0
*/
CSS);
        }

        $template = $themePath.DIRECTORY_SEPARATOR.'index.php';
        if (! is_file($template)) {
            file_put_contents($template, "<?php\n\ndeclare(strict_types=1);\n");
        }
    }
}
