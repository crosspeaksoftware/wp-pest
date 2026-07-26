<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Config;

use CrossPeak\WpPest\Matrix\MatrixExpander;

final class ConfigLoader
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'target' => 'site',
            'tests' => ['code'],
            'site' => [
                'url' => null,
                'path' => null,
            ],
            'wordpress' => [
                'version' => '^7.0',
            ],
            'plugin' => [
                'path' => null,
            ],
            'plugins' => [
                'dependencies' => [],
            ],
            'sandbox' => [
                'path' => '.wp-pest/sandboxes',
            ],
            'matrix' => [
                'wordpress' => ['^7.0'],
                'plugins' => [],
                'profiles' => [
                    'default' => [
                        'tests' => ['code'],
                    ],
                ],
                'exclude' => [],
                'browser' => [
                    'database' => 'mariadb:11',
                    'env' => [],
                    'port' => 0,
                ],
                'max_combinations' => 100,
            ],
        ];
    }

    public function load(string $workingDirectory): Config
    {
        $path = rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wp-pest.php';
        $userConfig = [];

        if (is_file($path)) {
            $loaded = require $path;

            if (! is_array($loaded)) {
                throw new ValidationException(['wp-pest.php must return an array.']);
            }

            $userConfig = $loaded;
        }

        $config = $this->merge(self::defaults(), $userConfig);
        $userMatrix = $userConfig['matrix'] ?? null;
        if (! is_array($userMatrix) || ! array_key_exists('profiles', $userMatrix)) {
            $config['matrix']['profiles']['default']['tests'] = $config['tests'];
        }
        if ((! is_array($userMatrix) || ! array_key_exists('wordpress', $userMatrix)) && isset($userConfig['wordpress']['version'])) {
            $config['matrix']['wordpress'] = [$config['wordpress']['version']];
        }
        $config = $this->applyEnvironment($config);

        $this->validate($config);

        return new Config($config);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($key === 'profiles' && is_array($value)) {
                $base[$key] = $value;

                continue;
            }

            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = $this->merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyEnvironment(array $config): array
    {
        $this->setFromEnv($config, 'target', 'WP_PEST_TARGET');
        $this->setFromEnv($config, 'site.url', 'WP_PEST_URL');
        $this->setFromEnv($config, 'plugin.path', 'WP_PEST_PLUGIN_PATH');
        $this->setFromEnv($config, 'wordpress.version', 'WP_PEST_WORDPRESS_VERSION');

        $tests = getenv('WP_PEST_TESTS');
        if (is_string($tests) && $tests !== '') {
            $config['tests'] = array_values(array_filter(array_map('trim', explode(',', $tests))));
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function setFromEnv(array &$config, string $key, string $env): void
    {
        $value = getenv($env);

        if (! is_string($value) || $value === '') {
            return;
        }

        $cursor = &$config;
        $segments = explode('.', $key);
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validate(array $config): void
    {
        $errors = [];
        $target = $config['target'] ?? null;
        $tests = $config['tests'] ?? null;

        if (! in_array($target, ['site', 'plugin'], true)) {
            $errors[] = 'target must be either "site" or "plugin".';
        }

        if (! is_array($tests) || $tests === []) {
            $errors[] = 'tests must contain "code", "browser", or both.';
        } else {
            foreach ($tests as $test) {
                if (! in_array($test, ['code', 'browser'], true)) {
                    $errors[] = 'tests may only contain "code" and "browser".';
                }
            }
        }

        if ($target === 'site' && in_array('browser', (array) $tests, true) && empty($config['site']['url'])) {
            $errors[] = 'site.url is required when target is "site" and browser tests are enabled.';
        }

        if ($target === 'plugin' && empty($config['plugin']['path'])) {
            $errors[] = 'plugin.path is required when target is "plugin".';
        }

        $dependencies = $config['plugins']['dependencies'] ?? null;
        if (! is_array($dependencies)) {
            $errors[] = 'plugins.dependencies must be an array.';
        } else {
            foreach ($dependencies as $dependency) {
                if (is_string($dependency) && $dependency !== '') {
                    continue;
                }

                if (is_array($dependency) && $this->validPluginDependency($dependency)) {
                    continue;
                }

                $errors[] = 'Each plugin dependency must be a slug, path, URL, or an array containing slug, path, or url.';
            }
        }

        if (isset($config['sandbox']['matrix'])) {
            $errors[] = 'sandbox.matrix is no longer supported; move matrix configuration to the top-level matrix key.';
        }

        if (! is_array($config['matrix'] ?? null)) {
            $errors[] = 'matrix must be an array.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        (new MatrixExpander)->expand(new Config($config));
    }

    /** @param array<mixed> $dependency */
    private function validPluginDependency(array $dependency): bool
    {
        if (isset($dependency['version']) && (! is_string($dependency['version']) || $dependency['version'] === '')) {
            return false;
        }

        foreach (['slug', 'path', 'url'] as $key) {
            if (isset($dependency[$key]) && is_string($dependency[$key]) && $dependency[$key] !== '') {
                return $key !== 'url' || filter_var($dependency[$key], FILTER_VALIDATE_URL) !== false;
            }
        }

        return false;
    }
}
