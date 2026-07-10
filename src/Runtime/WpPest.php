<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ConfigLoader;
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

        if ($path === null) {
            return false;
        }

        $wpLoad = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wp-load.php';

        if (! is_file($wpLoad)) {
            return false;
        }

        require_once $wpLoad;

        return true;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }
}
