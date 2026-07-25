<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
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
        return $this->workingDirectory.DIRECTORY_SEPARATOR.'.wp-pest'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'wordpress';
    }

    public function ensure(): string
    {
        $path = $this->path();

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $composerJson = $path.DIRECTORY_SEPARATOR.'composer.json';
        $composerContents = json_encode([
            'repositories' => [
                [
                    'type' => 'composer',
                    'url' => 'https://wpackagist.org',
                ],
            ],
            'require' => [
                'roots/wordpress' => $this->config->wordpressVersion(),
                'wpackagist-plugin/sqlite-database-integration' => '^2.2',
            ],
            'extra' => [
                'wordpress-install-dir' => 'wordpress',
                'installer-paths' => [
                    'wordpress/wp-content/plugins/{$name}/' => ['type:wordpress-plugin'],
                ],
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

        if ($composerChanged && is_file($path.DIRECTORY_SEPARATOR.'composer.lock')) {
            $this->process->run(['composer', 'update', '--no-interaction'], $path, true);
        } elseif (! is_file($wordpressPath.DIRECTORY_SEPARATOR.'wp-load.php') || ! is_file($sqlitePath)) {
            $this->process->run(['composer', 'install'], $path, true);
        }

        $this->configureWordPress($wordpressPath);

        return $path;
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
        if (is_file($config)) {
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
}
