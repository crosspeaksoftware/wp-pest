<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Matrix;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\PluginDependency;
use CrossPeak\WpPest\Config\ValidationException;

final class MatrixExpander
{
    /** @return list<MatrixCombination> */
    public function expand(Config $config): array
    {
        $matrix = $config->matrixDefinition();
        $wordpressVersions = $matrix['wordpress'] ?? [$config->baseWordpressVersion()];
        $pluginDefinitions = $matrix['plugins'] ?? [];
        if (! is_array($pluginDefinitions)) {
            throw new ValidationException(['matrix.plugins must be an array of plugin axes.']);
        }
        $axes = $this->pluginAxes($pluginDefinitions);
        $profiles = $matrix['profiles'] ?? [
            'default' => ['tests' => $config->tests()],
        ];
        $browser = $matrix['browser'] ?? [];
        if (! is_array($browser)) {
            throw new ValidationException(['matrix.browser must be an array.']);
        }
        $exclusions = $matrix['exclude'] ?? [];
        $max = $matrix['max_combinations'] ?? 100;

        $this->validate($wordpressVersions, $axes, $profiles, $exclusions, $max);

        $axisSelections = $this->axisSelections($axes);
        $combinations = [];
        $fingerprints = [];
        $expandedCount = 0;

        foreach ($profiles as $profileName => $profile) {
            foreach ($wordpressVersions as $wordpress) {
                foreach ($axisSelections as $selection) {
                    $versions = [];
                    foreach ($selection as $slug => $dependency) {
                        $versions[$slug] = $dependency->version();
                    }

                    if ($this->excluded((string) $profileName, (string) $wordpress, $versions, $exclusions)) {
                        continue;
                    }

                    $plugins = $this->effectivePlugins($config, $selection, $profile);
                    $effectiveVersions = [];
                    foreach ($plugins as $plugin) {
                        $effectiveVersions[$plugin->slug()] = $plugin->version();
                    }
                    $tests = array_values($profile['tests']);
                    $groups = array_values($profile['groups'] ?? []);
                    $fingerprint = json_encode([
                        $wordpress,
                        $tests,
                        array_map(static fn (PluginDependency $plugin): string => $plugin->packageName().'@'.$plugin->version(), $plugins),
                        $groups,
                        $profile['database'] ?? $browser['database'] ?? 'mariadb:11',
                        $profile['env'] ?? $browser['env'] ?? [],
                    ], JSON_THROW_ON_ERROR);

                    if (isset($fingerprints[$fingerprint])) {
                        continue;
                    }
                    $fingerprints[$fingerprint] = true;
                    $expandedCount++;
                    if ($expandedCount > $max) {
                        throw new ValidationException(["Matrix expands to more than {$max} combinations after exclusions. Add exclusions or raise max_combinations."]);
                    }

                    $name = $this->name((string) $profileName, (string) $wordpress, $versions);
                    if (isset($combinations[$name])) {
                        $name .= '--'.substr(sha1($fingerprint), 0, 8);
                    }

                    $combinations[$name] = new MatrixCombination(
                        $name,
                        (string) $profileName,
                        (string) $wordpress,
                        $tests,
                        $plugins,
                        $groups,
                        (string) ($profile['database'] ?? $browser['database'] ?? 'mariadb:11'),
                        array_map('strval', $profile['env'] ?? $browser['env'] ?? []),
                        (int) ($profile['port'] ?? $browser['port'] ?? 0),
                        $effectiveVersions,
                    );
                }
            }
        }

        return array_values($combinations);
    }

    /**
     * @param  array<array-key, mixed>  $axes
     * @return array<string, list<PluginDependency>>
     */
    private function pluginAxes(array $axes): array
    {
        $result = [];
        foreach ($axes as $slug => $variants) {
            if (! is_string($slug) || $slug === '') {
                throw new ValidationException(['Every matrix.plugins axis must have a non-empty plugin slug.']);
            }
            if (! is_array($variants)) {
                throw new ValidationException(["matrix.plugins.{$slug} must be a list of plugin versions."]);
            }

            $result[(string) $slug] = array_map(
                static function (mixed $variant) use ($slug): PluginDependency {
                    if (is_string($variant) && $variant !== '') {
                        return PluginDependency::from(['slug' => (string) $slug, 'version' => $variant]);
                    }

                    if (! is_array($variant)
                        || (! isset($variant['version']) && ! isset($variant['url']) && ! isset($variant['path']))
                        || (isset($variant['version']) && (! is_string($variant['version']) || $variant['version'] === ''))
                        || (isset($variant['url']) && (! is_string($variant['url']) || filter_var($variant['url'], FILTER_VALIDATE_URL) === false))
                        || (isset($variant['path']) && (! is_string($variant['path']) || $variant['path'] === ''))) {
                        throw new ValidationException(["Every matrix.plugins.{$slug} value must be a version string or valid plugin definition."]);
                    }

                    return PluginDependency::from(array_merge(['slug' => (string) $slug], $variant));
                },
                array_values($variants),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, list<PluginDependency>>  $axes
     * @return list<array<string, PluginDependency>>
     */
    private function axisSelections(array $axes): array
    {
        $selections = [[]];
        foreach ($axes as $slug => $variants) {
            $next = [];
            foreach ($selections as $selection) {
                foreach ($variants as $variant) {
                    $next[] = array_merge($selection, [$slug => $variant]);
                }
            }
            $selections = $next;
        }

        return $selections;
    }

    /**
     * @param  array<string, PluginDependency>  $selection
     * @param  array<string, mixed>  $profile
     * @return list<PluginDependency>
     */
    private function effectivePlugins(Config $config, array $selection, array $profile): array
    {
        $plugins = ($profile['inherit_plugins'] ?? true) ? $config->baseDependencyPlugins() : [];
        $plugins = array_merge($plugins, array_values($selection));
        foreach ($profile['plugins'] ?? [] as $plugin) {
            $plugins[] = PluginDependency::from($plugin);
        }

        $excluded = array_map(
            static fn (string $slug): string => PluginDependency::from($slug)->slug(),
            array_values($profile['exclude_plugins'] ?? []),
        );
        $bySlug = [];
        foreach ($plugins as $plugin) {
            if (! in_array($plugin->slug(), $excluded, true)) {
                $bySlug[$plugin->slug()] = $plugin;
            }
        }

        return array_values($bySlug);
    }

    /**
     * @param  array<string, string>  $versions
     * @param  list<array<string, mixed>>  $exclusions
     */
    private function excluded(string $profile, string $wordpress, array $versions, array $exclusions): bool
    {
        foreach ($exclusions as $rule) {
            if (isset($rule['profile']) && $rule['profile'] !== $profile) {
                continue;
            }
            if (isset($rule['wordpress']) && $rule['wordpress'] !== $wordpress) {
                continue;
            }

            $matches = true;
            foreach ($rule['plugins'] ?? [] as $slug => $version) {
                if (($versions[$slug] ?? null) !== $version) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $versions */
    private function name(string $profile, string $wordpress, array $versions): string
    {
        $parts = [$profile, 'wp-'.$wordpress];
        foreach ($versions as $slug => $version) {
            $parts[] = $slug.'-'.$version;
        }

        return implode('--', array_map(
            static fn (string $part): string => trim(
                (string) preg_replace('/[^a-z0-9._]+/i', '-', str_replace('*', 'x', $part)),
                '-._',
            ),
            $parts,
        ));
    }

    /**
     * @param  array<string, list<PluginDependency>>  $axes
     */
    private function validate(mixed $wordpressVersions, array $axes, mixed $profiles, mixed $exclusions, mixed $max): void
    {
        $errors = [];
        if (! is_array($wordpressVersions) || ! array_is_list($wordpressVersions) || $wordpressVersions === [] || array_filter($wordpressVersions, 'is_string') !== $wordpressVersions) {
            $errors[] = 'matrix.wordpress must be a non-empty list of version constraints.';
        }
        foreach ($axes as $slug => $variants) {
            if ($slug === '' || $variants === []) {
                $errors[] = 'Every matrix.plugins axis must have a slug and at least one version.';
            }
        }
        if (! is_array($profiles) || $profiles === []) {
            $errors[] = 'matrix.profiles must contain at least one named profile.';
        } else {
            foreach ($profiles as $name => $profile) {
                $tests = is_array($profile) ? ($profile['tests'] ?? null) : null;
                if (! is_string($name) || $name === '' || ! is_array($tests) || ! array_is_list($tests) || $tests === [] || array_filter($tests, 'is_string') !== $tests || array_diff($tests, ['code', 'browser']) !== []) {
                    $errors[] = 'Every matrix profile must have a name and a non-empty tests list containing code, browser, or both.';
                }
                if (! is_array($profile)) {
                    continue;
                }
                if (isset($profile['plugins']) && ! is_array($profile['plugins'])) {
                    $errors[] = "matrix.profiles.{$name}.plugins must be an array.";
                } elseif (isset($profile['plugins'])) {
                    foreach ($profile['plugins'] as $plugin) {
                        if (! $this->validPluginDefinition($plugin)) {
                            $errors[] = "Every matrix.profiles.{$name}.plugins value must be a slug, path, URL, or valid plugin definition.";
                        }
                    }
                }
                if (isset($profile['inherit_plugins']) && ! is_bool($profile['inherit_plugins'])) {
                    $errors[] = "matrix.profiles.{$name}.inherit_plugins must be a boolean.";
                }
                foreach (['exclude_plugins', 'groups'] as $key) {
                    if (isset($profile[$key]) && (! is_array($profile[$key]) || array_filter($profile[$key], 'is_string') !== $profile[$key])) {
                        $errors[] = "matrix.profiles.{$name}.{$key} must be a list of strings.";
                    }
                }
                if (isset($profile['database']) && ! is_string($profile['database'])) {
                    $errors[] = "matrix.profiles.{$name}.database must be a string.";
                }
                if (isset($profile['env']) && ! is_array($profile['env'])) {
                    $errors[] = "matrix.profiles.{$name}.env must be an array.";
                }
                if (isset($profile['port']) && ! is_int($profile['port'])) {
                    $errors[] = "matrix.profiles.{$name}.port must be an integer.";
                }
            }
        }
        if (! is_array($exclusions)) {
            $errors[] = 'matrix.exclude must be an array.';
        } else {
            foreach ($exclusions as $index => $rule) {
                if (! is_array($rule)) {
                    $errors[] = "matrix.exclude[{$index}] must be an array.";

                    continue;
                }
                if (isset($rule['profile']) && (! is_array($profiles) || ! array_key_exists((string) $rule['profile'], $profiles))) {
                    $errors[] = "matrix.exclude[{$index}] references an unknown profile.";
                }
                if (isset($rule['wordpress']) && (! is_array($wordpressVersions) || ! in_array($rule['wordpress'], $wordpressVersions, true))) {
                    $errors[] = "matrix.exclude[{$index}] references an unknown WordPress version.";
                }
                if (isset($rule['plugins']) && ! is_array($rule['plugins'])) {
                    $errors[] = "matrix.exclude[{$index}].plugins must be an array.";

                    continue;
                }
                foreach ($rule['plugins'] ?? [] as $slug => $version) {
                    if (! isset($axes[$slug])) {
                        $errors[] = "matrix.exclude[{$index}] references unknown plugin axis {$slug}.";

                        continue;
                    }
                    if (! in_array($version, array_map(
                        static fn (PluginDependency $plugin): string => $plugin->version(),
                        $axes[$slug],
                    ), true)) {
                        $errors[] = "matrix.exclude[{$index}] references unknown version {$version} for plugin {$slug}.";
                    }
                }
            }
        }
        if (! is_int($max) || $max < 1) {
            $errors[] = 'matrix.max_combinations must be a positive integer.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function validPluginDefinition(mixed $plugin): bool
    {
        if (is_string($plugin)) {
            return $plugin !== '';
        }
        if (! is_array($plugin)) {
            return false;
        }
        if (isset($plugin['version']) && (! is_string($plugin['version']) || $plugin['version'] === '')) {
            return false;
        }
        if (isset($plugin['url'])) {
            return is_string($plugin['url']) && filter_var($plugin['url'], FILTER_VALIDATE_URL) !== false;
        }

        return (isset($plugin['slug']) && is_string($plugin['slug']) && $plugin['slug'] !== '')
            || (isset($plugin['path']) && is_string($plugin['path']) && $plugin['path'] !== '');
    }
}
