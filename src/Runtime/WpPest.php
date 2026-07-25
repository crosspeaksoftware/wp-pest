<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use CrossPeak\WpPest\Support\SiteDetector;

final class WpPest
{
    private static ?self $instance = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
    ) {}

    public static function fromCurrentWorkingDirectory(): self
    {
        return self::$instance ??= new self(
            (new ConfigLoader)->load(getcwd() ?: '.'),
            getcwd() ?: '.',
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function target(): string
    {
        return $this->config->target();
    }

    public function hasCodeTests(): bool
    {
        return $this->config->hasCodeTests();
    }

    public function hasBrowserTests(): bool
    {
        return $this->config->hasBrowserTests();
    }

    public function url(): ?string
    {
        return $this->config->siteUrl() ?? getenv('WP_PEST_ACTIVE_URL') ?: null;
    }

    public function wordpressPath(): ?string
    {
        if ($this->target() === 'site') {
            return $this->config->sitePath()
                ? $this->absolutePath($this->config->sitePath())
                : (new SiteDetector)->detect($this->workingDirectory);
        }

        $fixture = getenv('WP_PEST_WORDPRESS_PATH');

        return is_string($fixture) && $fixture !== '' ? $fixture : null;
    }

    public function pluginPath(): ?string
    {
        $path = $this->config->pluginPath();

        return $path === null ? null : $this->absolutePath($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sandbox(): ?array
    {
        $name = getenv('WP_PEST_MATRIX');

        if (! is_string($name) || $name === '') {
            return null;
        }

        return $this->config->sandboxMatrix()[$name] ?? null;
    }

    public function bootstrapWordPress(): bool
    {
        $path = $this->wordpressPath();

        if ($path === null && $this->target() === 'plugin') {
            $fixture = (new PluginFixture($this->config, $this->workingDirectory))->ensure();
            $path = $fixture.DIRECTORY_SEPARATOR.'wordpress';
        }

        if ($path === null) {
            return false;
        }

        $wpLoad = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wp-load.php';

        if (! is_file($wpLoad)) {
            return false;
        }

        require_once $wpLoad;

        if ($this->target() === 'plugin') {
            $this->installWordPress();
            $this->loadDependencies($path);
            $this->loadPlugin($this->pluginPath());
        }

        return true;
    }

    private function installWordPress(): void
    {
        $isBlogInstalledName = 'is_blog_installed';
        if (! function_exists($isBlogInstalledName)) {
            return;
        }

        $installingName = 'wp_installing';
        if ($this->callFunction($isBlogInstalledName)) {
            if (function_exists($installingName)) {
                $this->callFunction($installingName, false);
            }

            return;
        }

        $absolutePath = defined('ABSPATH') ? constant('ABSPATH') : null;
        if (! is_string($absolutePath)) {
            return;
        }

        require_once $absolutePath.'wp-admin/includes/upgrade.php';

        $installName = 'wp_install';
        $addFilterName = 'add_filter';
        $removeFilterName = 'remove_filter';
        $skipMail = static fn (): bool => true;

        if (function_exists($addFilterName)) {
            $this->callFunction($addFilterName, 'pre_wp_mail', $skipMail);
        }

        if (function_exists($installName)) {
            $this->callFunction($installName, 'WP Pest', 'admin', 'admin@example.test', true, '', 'password');
        }

        if (function_exists($removeFilterName)) {
            $this->callFunction($removeFilterName, 'pre_wp_mail', $skipMail);
        }

        if (function_exists($installingName)) {
            $this->callFunction($installingName, false);
        }
    }

    private function loadDependencies(string $wordpressPath): void
    {
        foreach ($this->config->dependencyPlugins() as $dependency) {
            $path = $dependency->isLocalPath()
                ? $this->absolutePath($dependency->source())
                : $wordpressPath.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.$dependency->slug();

            $this->loadPlugin($path);
        }
    }

    private function loadPlugin(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        foreach (glob($path.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $getFileDataName = 'get_file_data';
            if (! function_exists($getFileDataName)) {
                return;
            }

            $headers = $this->callFunction($getFileDataName, $file, ['name' => 'Plugin Name'], 'plugin');

            if (is_array($headers) && ($headers['name'] ?? '') !== '') {
                require_once $file;

                return;
            }
        }
    }

    private function callFunction(string $name, mixed ...$arguments): mixed
    {
        return (new \ReflectionFunction($name))->invokeArgs($arguments);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }
}
