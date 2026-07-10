<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Support\Process;

final class PluginFixture
{
    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly Process $process = new Process,
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
        if (! is_file($composerJson)) {
            file_put_contents($composerJson, json_encode([
                'repositories' => [
                    [
                        'type' => 'composer',
                        'url' => 'https://wpackagist.org',
                    ],
                ],
                'require' => [
                    'johnpbloch/wordpress-core-installer' => '^2.0',
                    'johnpbloch/wordpress-core' => $this->config->wordpressVersion(),
                ],
                'extra' => [
                    'wordpress-install-dir' => 'wordpress',
                ],
                'config' => [
                    'allow-plugins' => [
                        'johnpbloch/wordpress-core-installer' => true,
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        if (! is_file($path.DIRECTORY_SEPARATOR.'wordpress'.DIRECTORY_SEPARATOR.'wp-load.php')) {
            $this->process->run(['composer', 'install'], $path, true);
        }

        return $path;
    }
}
