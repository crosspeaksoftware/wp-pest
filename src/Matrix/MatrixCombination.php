<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Matrix;

use CrossPeak\WpPest\Config\PluginDependency;

final readonly class MatrixCombination
{
    /**
     * @param  list<string>  $tests
     * @param  list<PluginDependency>  $plugins
     * @param  list<string>  $groups
     * @param  array<string, string>  $env
     * @param  array<string, string>  $pluginVersions
     */
    public function __construct(
        private string $name,
        private string $profile,
        private string $wordpress,
        private array $tests,
        private array $plugins,
        private array $groups,
        private string $database,
        private array $env,
        private int $port,
        private array $pluginVersions,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function wordpress(): string
    {
        return $this->wordpress;
    }

    /** @return list<string> */
    public function tests(): array
    {
        return $this->tests;
    }

    /** @return list<PluginDependency> */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function database(): string
    {
        return $this->database;
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return $this->env;
    }

    public function port(): int
    {
        return $this->port;
    }

    /** @return array<string, string> */
    public function pluginVersions(): array
    {
        return $this->pluginVersions;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'name' => $this->name,
            'profile' => $this->profile,
            'wordpress' => $this->wordpress,
            'tests' => $this->tests,
            'plugins' => $this->pluginVersions,
            'groups' => $this->groups,
            'database' => $this->database,
            'env' => $this->env,
            'port' => $this->port,
        ];
    }
}
