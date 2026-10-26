<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use CrossPeak\WpPest\Runtime\VersionConstraint;

/** A fresh observation of the WordPress process used by a test. */
final readonly class Environment
{
    /** @param array<string, array{file: string, version: ?string, active: bool}> $plugins */
    public function __construct(
        public string $php,
        public string $wordpress,
        public string $database,
        public ?string $databaseVersion,
        public array $plugins,
    ) {}

    public function hasPlugin(string $slug): bool
    {
        return $this->plugins[$slug]['active'] ?? false;
    }

    public function pluginVersion(string $slug): ?string
    {
        return $this->plugins[$slug]['version'] ?? null;
    }

    public function pluginVersionIs(string $slug, string $constraint): bool
    {
        $version = $this->pluginVersion($slug);

        return $version !== null && VersionConstraint::matches($version, $constraint);
    }
}
