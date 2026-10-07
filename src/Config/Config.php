<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Config;

use CrossPeak\WpPest\Matrix\MatrixCombination;
use CrossPeak\WpPest\Matrix\MatrixExpander;

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
        $environment = getenv('WP_PEST_TESTS');
        $profiles = $this->get('matrix.profiles');
        if ((! is_string($environment) || $environment === '') && is_array($profiles) && $profiles !== []) {
            return $this->activeMatrix()?->tests() ?? array_values($this->values['tests']);
        }

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
        return $this->nullableString('plugin.path') ?? ($this->target() === 'plugin' ? '.' : null);
    }

    public function activatePlugins(): bool
    {
        return (bool) $this->get('plugins.activate', true);
    }

    public function useDatabaseTransactions(): bool
    {
        return (bool) $this->get('database.transactions', true);
    }

    public function sqliteJournalMode(): string
    {
        return strtoupper((string) $this->get('database.sqlite.journal_mode', 'MEMORY'));
    }

    public function sqliteSynchronous(): string
    {
        return strtoupper((string) $this->get('database.sqlite.synchronous', 'OFF'));
    }

    public function wordpressVersion(): string
    {
        return $this->activeMatrix()?->wordpress() ?? $this->baseWordpressVersion();
    }

    /** @return list<PluginDependency> */
    public function dependencyPlugins(): array
    {
        return $this->activeMatrix()?->plugins() ?? $this->baseDependencyPlugins();
    }

    public function baseWordpressVersion(): string
    {
        return (string) $this->get('wordpress.version', '^7.0');
    }

    /** @return array<string, mixed> */
    public function wordpressOptions(): array
    {
        $options = $this->get('wordpress.options', []);

        return is_array($options) ? $options : [];
    }

    /** @return list<PluginDependency> */
    public function baseDependencyPlugins(): array
    {
        $dependencies = $this->get('plugins.dependencies', []);

        if (! is_array($dependencies)) {
            return [];
        }

        return array_values(array_map(
            static fn (string|array $dependency): PluginDependency => PluginDependency::from($dependency),
            $dependencies,
        ));
    }

    /** @return array<string, mixed> */
    public function matrixDefinition(): array
    {
        $matrix = $this->get('matrix', []);

        return is_array($matrix) ? $matrix : [];
    }

    /** @return list<MatrixCombination> */
    public function matrixCombinations(): array
    {
        return (new MatrixExpander)->expand($this);
    }

    public function activeMatrix(): ?MatrixCombination
    {
        $name = getenv('WP_PEST_MATRIX');
        if (! is_string($name) || $name === '') {
            return $this->matrixCombinations()[0] ?? null;
        }

        foreach ($this->matrixCombinations() as $combination) {
            if ($combination->name() === $name) {
                return $combination;
            }
        }

        throw new ValidationException(["Unknown matrix combination: {$name}."]);
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
