# crosspeak/wp-pest

Pest tooling for testing WordPress sites and plugins.

## Modes

The package uses two independent settings instead of four hard-coded modes:

- `target`: `site` or `plugin`
- `tests`: `code`, `browser`, or both

That supports these common runs:

- Existing site browser tests: `target=site`, `tests=['browser']`, with `site.url`.
- Existing site code tests: `target=site`, `tests=['code']`, with auto-detected or configured `site.path`.
- Plugin code tests: `target=plugin`, `tests=['code']`, with `plugin.path`.
- Plugin browser tests: `target=plugin`, `tests=['browser']`, with Docker sandbox matrix entries.

## Install

```bash
composer require --dev crosspeak/wp-pest
composer require --dev pestphp/pest-plugin-browser # for browser tests
vendor/bin/wp-pest init
```

By default, `init` creates a site-mode config for a full WordPress site. You can also be explicit:

```bash
vendor/bin/wp-pest init --target=site
```

Use plugin mode when installing into a plugin repository:

```bash
vendor/bin/wp-pest init --target=plugin
```

`init` creates example tests for the selected target:

- `tests/Code/SiteTest.php` and `tests/Browser/SiteTest.php` for site mode.
- `tests/Code/PluginTest.php` and `tests/Browser/PluginTest.php` for plugin mode.

Run the generated code and browser tests with:

```bash
vendor/bin/pest
vendor/bin/pest --testsuite=Code
vendor/bin/pest --testsuite=Browser
```

The generated `tests/Pest.php` bootstraps WordPress before the tests execute.
It also increases Pest Browser's default wait timeout from five seconds to one minute.
The generated browser tests assert that the page source includes a document body and that there are no JavaScript errors.

For plugin Code tests, the first run creates a real WordPress fixture under `.wp-pest/fixtures`, backed by the WordPress SQLite integration so no external database service is required. WordPress is installed and the plugin under test is loaded before the Code suite runs.

## Configuration

Create `wp-pest.php` in the project root:

```php
<?php

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],
    'plugin' => ['path' => '.'],
    'sandbox' => [
        'matrix' => [
            'php83-wp-latest' => [
                'php' => '8.3',
                'wordpress' => '^7.0',
                'database' => 'mariadb:11',
                'port' => 8080,
            ],
            'php84-wp-latest' => [
                'php' => '8.4',
                'wordpress' => '^7.0',
                'database' => 'mariadb:11',
                'port' => 8081,
            ],
        ],
    ],
];
```

## CLI

```bash
vendor/bin/wp-pest doctor
vendor/bin/pest
vendor/bin/pest --testsuite=Code
vendor/bin/pest --testsuite=Browser
vendor/bin/wp-pest run --matrix=all
vendor/bin/wp-pest sandbox:start php83-wp-latest
vendor/bin/wp-pest sandbox:reset php83-wp-latest
vendor/bin/wp-pest sandbox:destroy php83-wp-latest
```

## Runtime helpers

```php
wp_pest()->target();
wp_pest()->hasCodeTests();
wp_pest()->hasBrowserTests();
wp_pest()->url();
wp_pest()->wordpressPath();
wp_pest()->pluginPath();
wp_pest()->sandbox();
wp_pest()->bootstrapWordPress(); // manual fallback for custom runners
```

## CI overrides

- `WP_PEST_TARGET`
- `WP_PEST_TESTS`
- `WP_PEST_URL`
- `WP_PEST_PLUGIN_PATH`
- `WP_PEST_WORDPRESS_VERSION`
- `WP_PEST_MATRIX`
