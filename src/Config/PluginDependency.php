<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Config;

final readonly class PluginDependency
{
    private function __construct(
        private string $type,
        private string $slug,
        private string $source,
        private string $version,
    ) {}

    /**
     * @param  string|array{slug?: string, version?: string, url?: string, path?: string}  $dependency
     */
    public static function from(string|array $dependency): self
    {
        if (is_array($dependency)) {
            if (isset($dependency['url'])) {
                $source = $dependency['url'];

                return new self(
                    'url',
                    self::normalizeSlug($dependency['slug'] ?? self::slugFromUrl($source)),
                    $source,
                    $dependency['version'] ?? self::versionFromUrl($source),
                );
            }

            if (isset($dependency['path'])) {
                $path = $dependency['path'];

                return new self(
                    'path',
                    self::normalizeSlug($dependency['slug'] ?? basename($path)),
                    $path,
                    $dependency['version'] ?? '*',
                );
            }

            $slug = $dependency['slug'] ?? '';

            return new self('wpackagist', self::normalizeSlug($slug), $slug, $dependency['version'] ?? '*');
        }

        if (filter_var($dependency, FILTER_VALIDATE_URL) !== false) {
            return new self(
                'url',
                self::slugFromUrl($dependency),
                $dependency,
                self::versionFromUrl($dependency),
            );
        }

        if (str_starts_with($dependency, 'wpackagist-plugin/')) {
            $slug = substr($dependency, strlen('wpackagist-plugin/'));

            return new self('wpackagist', self::normalizeSlug($slug), $dependency, '*');
        }

        if (str_starts_with($dependency, '.') || str_starts_with($dependency, DIRECTORY_SEPARATOR) || str_contains($dependency, DIRECTORY_SEPARATOR)) {
            return new self('path', self::normalizeSlug(basename($dependency)), $dependency, '*');
        }

        return new self('wpackagist', self::normalizeSlug($dependency), $dependency, '*');
    }

    public function isLocalPath(): bool
    {
        return $this->type === 'path';
    }

    public function isUrl(): bool
    {
        return $this->type === 'url';
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function packageName(): string
    {
        if ($this->isUrl()) {
            return 'wp-pest-plugin/'.$this->slug.'-'.substr(sha1($this->source), 0, 12);
        }

        return 'wpackagist-plugin/'.$this->slug;
    }

    private static function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = pathinfo(basename($path), PATHINFO_FILENAME);
        $name = (string) preg_replace('/[._-]v?\d+(?:\.\d+)+(?:[._-].*)?$/i', '', $name);

        return self::normalizeSlug($name);
    }

    private static function versionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = pathinfo(basename($path), PATHINFO_FILENAME);

        return preg_match('/[._-]v?(\d+(?:\.\d+)+)/i', $name, $matches) === 1
            ? $matches[1]
            : '1.0.0';
    }

    private static function normalizeSlug(string $slug): string
    {
        return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($slug)), '-');
    }
}
