<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use CrossPeak\WpPest\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RuntimeException;

final class Requirements
{
    /** @param array<int|string, string|null> $plugins */
    public static function check(WpPest $runtime, array $plugins = [], ?string $wordpress = null, ?string $php = null, ?string $database = null, string $context = 'auto'): Environment
    {
        if ($database !== null && ! in_array($database, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Database requirement must be sqlite, mysql, or mariadb.');
        }
        $required = [];
        foreach ($plugins as $slug => $constraint) {
            if (is_int($slug)) {
                $slug = $constraint;
                $constraint = null;
            }
            if (! is_string($slug) || $slug === '') {
                throw new InvalidArgumentException('Plugin requirements must be slugs or slug => version comparison pairs.');
            }
            $slug = str_starts_with($slug, 'wpackagist-plugin/') ? substr($slug, strlen('wpackagist-plugin/')) : $slug;
            if (preg_match('/^[a-z0-9_-]+$/i', $slug) !== 1) {
                throw new InvalidArgumentException('Plugin requirements must use a plugin slug: '.$slug);
            }
            $required[strtolower($slug)] = $constraint;
        }
        foreach (array_merge(array_values($required), [$wordpress, $php]) as $constraint) {
            if ($constraint !== null) {
                VersionConstraint::matches('0', $constraint);
            }
        }
        $environment = $runtime->environment($context);
        // Check setup failures before considering any legitimate matrix skips.
        $runtime->assertExpectedPlugins($environment);
        foreach ($required as $slug => $constraint) {
            if (! $environment->hasPlugin($slug)) {
                Assert::markTestSkipped('Requires active plugin '.$slug.'; it is not active in this matrix combination.');
            }
            if ($constraint !== null) {
                $version = $environment->pluginVersion($slug);
                if ($version === null) {
                    throw new RuntimeException('The active plugin has no readable version header: '.$slug);
                }
                if (! VersionConstraint::matches($version, $constraint)) {
                    Assert::markTestSkipped('Requires '.$slug.' '.$constraint.'; the running version is '.$version.'.');
                }
            }
        }
        foreach (['WordPress' => [$wordpress, $environment->wordpress], 'PHP' => [$php, $environment->php]] as $name => [$constraint, $version]) {
            if ($constraint !== null && ! VersionConstraint::matches($version, $constraint)) {
                Assert::markTestSkipped('Requires '.$name.' '.$constraint.'; the running version is '.$version.'.');
            }
        }
        if ($database !== null && $environment->database !== $database && ! ($database === 'mysql' && $environment->database === 'mariadb')) {
            Assert::markTestSkipped('Requires '.$database.'; the running database is '.$environment->database.'.');
        }

        return $environment;
    }
}
