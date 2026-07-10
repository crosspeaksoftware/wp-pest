<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Config;

final class Config
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    public function target(): string
    {
        return (string) $this->values['target'];
    }

    /**
     * @return list<string>
     */
    public function tests(): array
    {
        return array_values($this->values['tests']);
    }

    public function hasCodeTests(): bool
    {
        return in_array('code', $this->tests(), true);
    }

    public function hasBrowserTests(): bool
    {
        return in_array('browser', $this->tests(), true);
    }

    public function siteUrl(): ?string
    {
        return $this->nullableString('site.url');
    }

    public function sitePath(): ?string
    {
        return $this->nullableString('site.path');
    }

    public function pluginPath(): ?string
    {
        return $this->nullableString('plugin.path');
    }

    public function wordpressVersion(): string
    {
        return (string) $this->get('wordpress.version', '^6.0');
    }

    /**
     * @return list<string>
     */
    public function dependencyPlugins(): array
    {
        $dependencies = $this->get('plugins.dependencies', []);

        return is_array($dependencies) ? array_values(array_map('strval', $dependencies)) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sandboxMatrix(): array
    {
        $matrix = $this->get('sandbox.matrix', []);

        return is_array($matrix) ? $matrix : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
