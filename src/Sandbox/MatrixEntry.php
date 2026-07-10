<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

final class MatrixEntry
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public readonly string $name,
        private readonly array $values,
    ) {}

    public function php(): string
    {
        return (string) ($this->values['php'] ?? '8.3');
    }

    public function wordpress(): string
    {
        return (string) ($this->values['wordpress'] ?? '^7.0');
    }

    public function database(): string
    {
        return (string) ($this->values['database'] ?? 'mariadb:11');
    }

    public function port(): int
    {
        return (int) ($this->values['port'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function plugins(): array
    {
        $plugins = $this->values['plugins'] ?? [];

        return is_array($plugins) ? array_values(array_map('strval', $plugins)) : [];
    }

    /**
     * @return array<string, string>
     */
    public function env(): array
    {
        $env = $this->values['env'] ?? [];

        if (! is_array($env)) {
            return [];
        }

        return array_map('strval', $env);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
