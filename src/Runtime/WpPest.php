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

    /** @var array<string, string|null> */
    private array $loadedPlugins = [];

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

    public function matrixName(): ?string
    {
        $name = getenv('WP_PEST_MATRIX');

        return is_string($name) && $name !== '' ? $name : $this->config->activeMatrix()?->name();
    }

    /** @return array<string, mixed>|null */
    public function matrix(): ?array
    {
        return $this->config->activeMatrix()?->all();
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function phpVersionIs(string $constraint): bool
    {
        return $this->versionIs(PHP_VERSION, $constraint);
    }

    public function wordpressVersion(): ?string
    {
        $getBlogInfo = 'get_bloginfo';

        return function_exists($getBlogInfo) ? (string) $this->callFunction($getBlogInfo, 'version') : null;
    }

    public function wordpressVersionIs(string $constraint): bool
    {
        $version = $this->wordpressVersion();

        return $version !== null && $this->versionIs($version, $constraint);
    }

    public function hasPlugin(string $slug): bool
    {
        return array_key_exists($this->normalizeSlug($slug), $this->loadedPlugins);
    }

    public function pluginVersion(string $slug): ?string
    {
        return $this->loadedPlugins[$this->normalizeSlug($slug)] ?? null;
    }

    public function pluginVersionIs(string $slug, string $constraint): bool
    {
        $version = $this->pluginVersion($slug);

        return $version !== null && $this->versionIs($version, $constraint);
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

            $headers = $this->callFunction($getFileDataName, $file, ['name' => 'Plugin Name', 'version' => 'Version'], 'plugin');

            if (is_array($headers) && ($headers['name'] ?? '') !== '') {
                require_once $file;
                $this->loadedPlugins[$this->normalizeSlug(basename($path))] = ($headers['version'] ?? '') !== '' ? (string) $headers['version'] : null;

                return;
            }
        }
    }

    private function callFunction(string $name, mixed ...$arguments): mixed
    {
        return (new \ReflectionFunction($name))->invokeArgs($arguments);
    }

    private function versionIs(string $version, string $constraint): bool
    {
        if (preg_match('/^(>=|<=|!=|==|>|<|=)?\s*(.+)$/', trim($constraint), $matches) !== 1) {
            return false;
        }

        $operator = $matches[1] === '' ? '==' : $matches[1];
        $operator = $operator === '=' ? '==' : $operator;

        return version_compare($version, $matches[2], $operator);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = str_starts_with($slug, 'wpackagist-plugin/') ? substr($slug, strlen('wpackagist-plugin/')) : $slug;

        return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($slug)), '-');
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }
}
