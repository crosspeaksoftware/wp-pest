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
- Plugin browser tests: `target=plugin`, `tests=['browser']`, with Docker sandboxes generated from the matrix.

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

For plugin browser tests, `vendor/bin/pest --testsuite=Browser` automatically starts and stops the first generated matrix sandbox. Test runs remove the selected sandbox's WordPress and database volumes before setup so every run starts from a clean installation. The exact WordPress core and dependency plugin packages remain cached in the matrix fixture and are copied into the fresh runtime volume, avoiding repeat downloads after the first run. Use `vendor/bin/wp-pest run --tests=browser --matrix=<name>` to select a particular combination, or `--matrix=all` to run every combination. Manual `sandbox:start` remains non-destructive; use `sandbox:reset` when you explicitly want to recreate a manually managed sandbox.

The generated `tests/Pest.php` bootstraps WordPress before Code test files are discovered, while Browser-only runs skip the local WordPress fixture.
It also increases Pest Browser's default wait timeout from five seconds to one minute.
The generated browser tests assert that the page source includes a document body and that there are no JavaScript errors.

Browser tests for plugin targets can run WP-CLI inside the active matrix sandbox and capture its standard output. Pass each argument separately so commands and PHP snippets are escaped safely:

```php
$formId = wp_pest()->wpCli([
    'eval',
    '$form_id = GFAPI::add_form(["title" => "Test Form"]); echo $form_id;',
]);
```

This is useful for seeding browser fixtures and inspecting raw WordPress or plugin database state after UI interactions.

For browser tests, wp-pest also provides namespaced helpers for the common sandbox operations:

```php
use function CrossPeak\WpPest\{actingAsAdmin, wpEval};

$form = wpEval(<<<'PHP'
$form_id = GFAPI::add_form(['title' => 'Test Form']);
$page_id = wp_insert_post([
    'post_title' => 'Test Form',
    'post_content' => '[gravityform id="'.$form_id.'" title="false"]',
    'post_status' => 'publish',
    'post_type' => 'page',
]);

return ['form_id' => $form_id, 'url' => get_permalink($page_id)];
PHP);

actingAsAdmin()->navigate($form['url']);
```

`wpEval()` runs the provided PHP body in the active plugin sandbox with `$wpdb` available and returns its JSON-compatible result. It is intended for browser-test setup and inspection; use `wp_pest()->wpCli()` when the full WP-CLI output or a non-PHP CLI command is needed. `actingAsAdmin()` is limited to plugin browser sandboxes, logs in with the seeded `admin` / `password` account, and returns the browser page for chaining. It requires the optional Pest Browser plugin.

For plugin Code tests, the first run creates a real WordPress fixture under `.wp-pest/fixtures`, backed by the WordPress SQLite integration so no external database service is required. The fixture installs Twenty Twenty-Five from WordPress.org as its default theme, WordPress is installed, and the plugin under test is loaded before the Code suite runs.

## Configuration

Create `wp-pest.php` in the project root:

```php
<?php

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],
    'plugins' => [
        'dependencies' => [
            '../shared/local-plugin',
        ],
    ],
    'sandbox' => [
        'path' => '.wp-pest/sandboxes',
    ],
    'matrix' => [
        'wordpress' => ['^6.9', '^7.0'],
        'plugins' => [
            // WordPress.org releases are installed through WPackagist.
            'query-monitor' => ['3.19.0', '3.20.0'],
            // ZIP variants may point at plugins that are not on WPackagist.
            'premium-plugin' => [
                [
                    'version' => '1.2.3',
                    'url' => 'https://example.test/premium-plugin-1.2.3.zip',
                ],
                [
                    'version' => '1.3.0',
                    'url' => 'https://example.test/premium-plugin-1.3.0.zip',
                ],
            ],
        ],
        'profiles' => [
            'default' => [
                'tests' => ['code', 'browser'],
            ],
            'compatibility' => [
                'tests' => ['code'],
                'plugins' => ['woocommerce'],
                'groups' => ['woocommerce'],
            ],
        ],
        'exclude' => [
            [
                'wordpress' => '^6.9',
                'plugins' => ['premium-plugin' => '1.3.0'],
            ],
            [
                'profile' => 'compatibility',
                'plugins' => ['query-monitor' => '3.19.0'],
            ],
        ],
        'browser' => [
            'database' => 'mariadb:11',
            'port' => 8080,
        ],
        'max_combinations' => 100,
    ],
];
```

For plugin targets, `plugin.path` defaults to the current directory. You may set it when the plugin under test is in another directory.

Plugin dependencies are installed and loaded before the plugin under test in both Code fixtures and browser sandboxes. Use a WordPress.org plugin slug, a local directory path, a ZIP URL, or an explicit array with `slug`, `version`, and `url` keys. Explicit ZIP definitions are recommended when the filename does not contain a reliable plugin slug and version.
Only configure ZIP URLs that you trust: the downloaded plugin code is loaded and executed during the test bootstrap.

Every WordPress value, plugin-axis value, and profile is combined as a true Cartesian product. The example starts with 2 × 2 × 2 × 2 = 16 candidates and then applies the partial-match exclusion rules. `max_combinations` is checked after exclusions. Global `plugins.dependencies` are inherited by every profile; set `inherit_plugins` to `false`, add profile-only `plugins`, or remove inherited plugins with `exclude_plugins` when a compatibility suite needs a different environment.

PHP is deliberately controlled by the PHP process running Pest, rather than being another configuration axis. Configure PHP versions in the job matrix of GitHub Actions or your CI system; within each PHP job, `wp-pest` expands every configured WordPress/plugin/profile combination.

Browser sandboxes use the official WordPress image for the current PHP minor and then install the exact Composer-resolved WordPress matrix version into its shared volume. This avoids relying on Docker tags for every historical WordPress/PHP pairing.

## CLI

```bash
vendor/bin/wp-pest doctor
vendor/bin/pest
vendor/bin/pest --testsuite=Code
vendor/bin/pest --testsuite=Browser
vendor/bin/wp-pest matrix:list
vendor/bin/wp-pest run # first generated combination
vendor/bin/wp-pest run --matrix=all
vendor/bin/wp-pest run --matrix=default--wp-7.0--query-monitor-3.20.0--premium-plugin-1.3.0
vendor/bin/wp-pest sandbox:start default--wp-7.0--query-monitor-3.20.0--premium-plugin-1.3.0
vendor/bin/wp-pest sandbox:reset default--wp-7.0--query-monitor-3.20.0--premium-plugin-1.3.0
vendor/bin/wp-pest sandbox:destroy default--wp-7.0--query-monitor-3.20.0--premium-plugin-1.3.0
```

## Runtime helpers

```php
wp_pest()->target();
wp_pest()->hasCodeTests();
wp_pest()->hasBrowserTests();
wp_pest()->url();
wp_pest()->wordpressPath();
wp_pest()->pluginPath();
wp_pest()->matrixName();
wp_pest()->matrix();
wp_pest()->wpCli(['plugin', 'list']);
wp_pest()->phpVersion();
wp_pest()->phpVersionIs('>=8.3');
wp_pest()->wordpressVersion();
wp_pest()->wordpressVersionIs('>=6.9');
wp_pest()->hasPlugin('gravityforms');
wp_pest()->pluginVersion('gravityforms');
wp_pest()->pluginVersionIs('gravityforms', '>=2.10.5');
wp_pest()->bootstrapWordPress(); // manual fallback for custom runners
```

For Pest-style helpers, import `CrossPeak\WpPest\actingAsAdmin` and `CrossPeak\WpPest\wpEval` as shown above.

These Boolean helpers make version- or dependency-specific skips straightforward:

```php
test('integrates with Gravity Forms', function (): void {
    if (! wp_pest()->hasPlugin('gravityforms')) {
        $this->markTestSkipped('Gravity Forms is not active in this matrix combination.');
    }

    // Compatibility assertions...
});
```

## CI overrides

- `WP_PEST_TARGET`
- `WP_PEST_TESTS`
- `WP_PEST_URL`
- `WP_PEST_PLUGIN_PATH`
- `WP_PEST_WORDPRESS_VERSION`
- `WP_PEST_MATRIX`
- `WP_PEST_BROWSER_HOST` (host used to reach a plugin browser sandbox; defaults to `127.0.0.1`)
