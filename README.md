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

Generate a code-only plugin setup and install a dependency in one command:

```bash
vendor/bin/wp-pest init --target=plugin --tests=code --dependency=woocommerce
```

`--tests` accepts `code`, `browser`, or `code,browser`. Browser examples and
configuration are only generated when browser tests are selected.

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

Plugin browser tests use an automatic sandbox driver by default. A normal current-PHP/SQLite-compatible run uses the local sandbox; explicit MariaDB requirements, multiple PHP versions, or an explicit Docker selection use Docker. You can override the selection:

```bash
WP_PEST_SANDBOX=local vendor/bin/wp-pest run --tests=browser --matrix=<name>
WP_PEST_SANDBOX=docker vendor/bin/wp-pest run --tests=browser --matrix=<name>
```

The local driver copies the cached WordPress fixture, uses SQLite, runs PHP's built-in server, and executes WP-CLI locally. It requires PHP CLI, the SQLite extension, WP-CLI, and the browser test dependencies. Set `sandbox.driver` to `local`, `docker`, or `auto` in `tests/wp-pest.php`; `WP_PEST_SANDBOX` takes precedence over that setting.

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
use function CrossPeak\WpPest\{loginAsAdmin, wpEval};

$form = wpEval(<<<'PHP'
$form_id = GFAPI::add_form(['title' => 'Test Form']);
$page_id = wp_insert_post([
    'post_title' => 'Test Form',
    'post_content' => '[gravityform id="'.$form_id.'" title="false"]',
    'post_status' => 'publish',
    'post_type' => 'page',
]);

return ['form_id' => $form_id, 'path' => wp_make_link_relative(get_permalink($page_id))];
PHP);

visit($form['path'])->assertSee('Test Form');
loginAsAdmin()->navigate('/wp-admin/plugins.php')->assertSee('Plugins');
```

Pass data separately from PHP source; it is exposed as `$args` inside the sandbox:

```php
use function CrossPeak\WpPest\{wpEval, wpEvalFile};

$name = wpEval('return get_option($args["option"]);', ['option' => 'blogname']);

$page = wpEvalFile(__DIR__.'/fixtures/create-page.php', [
    'title' => 'A page with "quotes" and Unicode 東京',
]);
visit($page['path'])->assertSee($page['title']);
```

The fixture `tests/fixtures/create-page.php` contains ordinary PHP:

```php
<?php

$id = wp_insert_post([
    'post_title' => $args['title'],
    'post_type' => 'page',
    'post_status' => 'publish',
], true);
if (is_wp_error($id)) {
    throw new RuntimeException($id->get_error_message());
}

return [
    'id' => $id,
    'title' => get_the_title($id),
    'path' => wp_make_link_relative(get_permalink($id)),
];
```

`wpEvalFile()` accepts a readable file inside the plugin under test. Absolute paths
and paths relative to Pest's working directory are supported. The corresponding
file is required from the sandbox's plugin copy in both local and Docker drivers,
so `__FILE__`, `__DIR__`, relative includes, and PHP error locations refer to the
fixture in that sandbox. Local sandboxes copy files at startup; restart the sandbox
after editing a fixture during a manually managed session. Fixture files should
explicitly return their result (`require` otherwise returns `1`).

Both helpers expose `$args` and the WordPress `$wpdb` global. Arguments and return
values must be JSON-compatible; objects cross the boundary as associative arrays.
Use arrays and scalar values rather than PHP services or closures. Resources,
recursive data, non-finite numbers, and invalid UTF-8 produce JSON encoding errors. Fixture output and plugin output are ignored around the
returned result. Existing single-argument `wpEval($code)` calls remain supported.

`wpEval()` runs the provided PHP body in the active plugin sandbox with `$wpdb` available and returns its JSON-compatible result. The WordPress site runs in a separate Docker/PHP process, unlike a Laravel app booted inside Pest's process, so WordPress objects and services cannot be used directly in the test process. `wpEval()` and `wpEvalFile()` are the RPC boundary for setup and inspection; keep returned values JSON-compatible and use `wp_pest()->wpCli()` when full WP-CLI output or a non-PHP CLI command is needed. `loginAsAdmin()` is limited to plugin browser sandboxes, logs in with the seeded `admin` / `password` account, and returns a relative-navigation-aware browser page for chaining. These helpers require the optional Pest Browser plugin when used.

For plugin Code tests, the first run creates a real WordPress fixture under `<system-temp>/wp-pest-<project-hash>/fixtures`, backed by the WordPress SQLite integration so no external database service is required. Fixtures are cached separately for each project outside the plugin source tree, so symlinked plugins cannot cause WordPress to misidentify dependency paths. System temporary-directory cleanup may evict this cache; the next run recreates it. Existing `.wp-pest/fixtures` directories are no longer used and may be removed. The fixture installs Twenty Twenty-Five from WordPress.org as its default theme, WordPress is installed, and the plugin under test is loaded before the Code suite runs.

Dependency plugins and the plugin under test are activated while the fixture is
prepared, then the fixture completes one normal WordPress request so deferred
installation work is ready before Pest starts. The Pest process loads plugins
through WordPress's normal lifecycle, so `plugins_loaded`, `after_setup_theme`,
and `init` callbacks run at their expected times. Set `plugins.activate` to
`false` to mark plugins active without invoking activation hooks.

Generated Code suites extend `CrossPeak\WpPest\WordPressTestCase`. Existing suites
can enable the same lifecycle in `tests/Pest.php`, alongside the WordPress bootstrap:

```php
pest()->extend(\CrossPeak\WpPest\WordPressTestCase::class)->in('Code');
```

Remove any existing `beginDatabaseTransaction()` / `rollbackDatabaseTransaction()`
hooks when adopting this test case. It starts a transaction before Pest's
`beforeEach` hooks and rolls it back after `afterEach` hooks, even when a test or
cleanup callback fails. It also flushes the object cache and restores request
variables, hooks and action counts, the current user and screen, query globals,
admin menu globals, and script/style registries to their pre-test state.

Disable database rollback with `database.transactions` when a test intentionally
commits or manages its own database lifecycle. Request-state restoration and cache
flushing still run. MySQL DDL and explicit commits can escape a transaction;
those tests need their own database cleanup.

Plugin singleton/static state and filesystem writes need explicit cleanup. Register
callbacks as soon as you change that state; callbacks run in reverse order while
WordPress and the test transaction are still available:

```php
$this->deferCleanup(static function () use ($path): void {
    if (is_file($path)) {
        unlink($path);
    }
});
```

These helpers isolate in-process Code tests. Browser tests execute in a separate
WordPress process and continue to use the sandbox lifecycle. Disposable SQLite fixtures default to `MEMORY` journaling
and `synchronous=OFF`; both settings are configurable.

## Configuration

Create `tests/wp-pest.php`:

```php
<?php

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],
    'plugins' => [
        'dependencies' => [
            '../shared/local-plugin',
        ],
        'activate' => true,
    ],
    'database' => [
        'transactions' => true,
        'sqlite' => [
            'journal_mode' => 'MEMORY',
            'synchronous' => 'OFF',
        ],
    ],
    'sandbox' => [
        'path' => '.wp-pest/sandboxes',
        'driver' => 'auto',
    ],
    'matrix' => [
        'wordpress' => ['^6.9', '^7.0'],
        'php' => ['8.4', '8.5'],
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

Every WordPress value, PHP value, plugin-axis value, and profile is combined as a true Cartesian product. The example starts with 2 × 2 × 2 × 2 × 2 = 32 candidates and then applies the partial-match exclusion rules. `max_combinations` is checked after exclusions. Global `plugins.dependencies` are inherited by every profile; set `inherit_plugins` to `false`, add profile-only `plugins`, or remove inherited plugins with `exclude_plugins` when a compatibility suite needs a different environment.

The PHP process running Pest remains controlled by your CI job. Browser sandboxes may additionally define `matrix.php` as a list of PHP major.minor versions; each value becomes a matrix axis and selects the matching `wordpress:phpX.Y-apache` and `wordpress:cli-phpX.Y` images. If `matrix.php` is omitted, the current PHP minor is used. Code tests still execute in the PHP process running Pest.

Browser sandboxes use the official WordPress image for the selected PHP minor and then install the exact Composer-resolved WordPress matrix version into its shared volume. This avoids relying on Docker tags for every historical WordPress/PHP pairing.

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
wp_pest()->factory()->user->create(['role' => 'editor']);
wp_pest()->loginAsAdministrator();
```

Plugin fixtures can enforce WordPress options before the test process loads its
plugins and throughout each Code test. This is useful when plugin initialization
or a previous test changes an option value:

```php
'wordpress' => [
    'version' => '^6.5',
    'options' => [
        'woocommerce_checkout_company_field' => 'optional',
    ],
],
```

The same code-test helpers are available as namespaced functions:

```php
use function CrossPeak\WpPest\{loginAsAdministrator, factory};

$customerId = factory()->user->create(['role' => 'customer']);
$administratorId = loginAsAdministrator();
```

For Pest-style helpers, import `CrossPeak\WpPest\loginAsAdmin` and `CrossPeak\WpPest\wpEval` as shown above.

These Boolean helpers make version- or dependency-specific skips straightforward:

```php
test('integrates with Gravity Forms', function (): void {
    if (! wp_pest()->hasPlugin('gravityforms')) {
        $this->markTestSkipped('Gravity Forms is not active in this matrix combination.');
    }

    // Compatibility assertions...
});
```

## Data factories and authentication

Factories create real WordPress records and return IDs. Each factory supports
`createAndGet()` for a WordPress object and `createMany($count, $attributes)` for a
list of IDs. Attributes override the defaults:

```php
use function CrossPeak\WpPest\{factory, actingAs, actingAsRole, actingAsGuest};

$post = factory()->post->createAndGet(['post_title' => 'Private submission']);
$pageId = factory()->page->create(['post_content' => '[my-form]']);
$draftIds = factory()->post->createMany(3, ['post_status' => 'draft']);
$tagId = factory()->term->create(['taxonomy' => 'post_tag', 'name' => 'Encrypted']);
$user = factory()->user->createAndGet(['role' => 'editor']);
$attachmentId = factory()->attachment->create(['post_parent' => $post->ID]);

actingAs($user); // Accepts an existing WP_User or user ID.
actingAsRole('subscriber'); // Creates a new user with an installed role.
actingAsGuest();
```

Posts/pages default to published records; attachment records default to `inherit`
and `text/plain`; terms default to `category`; users default to `subscriber`.
Post/page/attachment attributes are raw values: do not call `wp_slash()` on them.
Bulk creation gives each record unique defaults; explicitly duplicated unique
attributes (such as user logins) still produce WordPress errors. Negative counts,
unknown authentication roles, and nonexistent users are rejected.

For a real attachment file, copy a readable fixture into WordPress uploads:

```php
$id = factory()->attachment->createFromFile(__DIR__.'/fixtures/photo.png', [
    'post_parent' => $post->ID,
]);
$this->deferCleanup(static fn () => wp_delete_attachment($id, true));
```

The original fixture is preserved. WordPress generates attachment metadata; register
cleanup before the database rollback so uploaded files and generated image sizes
are deleted too. Database transactions alone cannot remove files. Creating an
attachment with `create()` only makes a record and does not copy a file.

Use `actingAs()`, `actingAsRole()`, and `actingAsGuest()` for Code tests. The existing
`actingAsAdministrator()` still reuses a seeded administrator or creates one; an
account merely named `admin` is never treated as privileged without that role.
Use `loginAsAdmin()` for Browser tests. The older browser name `actingAsAdmin()`
remains an alias, so existing suites keep working. Code-test users and factories
operate in Pest's process, not the separate browser sandbox.

## Admin screens and responses

Code-test bootstrap loads the WordPress admin APIs before test isolation starts.
Tests can use screens, templates, menus, and the base list table without manually
requiring admin include files.

```php
use function CrossPeak\WpPest\{loginAsAdministrator, captureOutput, onAdminScreen};

loginAsAdministrator();
onAdminScreen('dashboard'); // Lasts until WordPressTestCase restores state.
$html = captureOutput(fn () => render_my_settings_page());
expect($html)->toContain('Settings');

// A callback scopes the screen to this operation and returns the callback result.
$html = onAdminScreen('settings_page_my_plugin', fn () =>
    captureOutput(fn () => render_my_settings_page())
);
```

`onAdminScreen()` restores the previous screen, post type, and taxonomy after a
scoped callback, including when it throws. It does not authenticate a user or fire
`admin_init`, `admin_menu`, or `load-*` actions; invoke the lifecycle actions your
test needs explicitly. `captureOutput()` ignores the callback's return value,
collects printed output, and discards it on failure while closing nested removable
buffers. Callbacks should leave the helper's buffer and the caller's buffers intact.

If a plugin checks `is_admin()` while loading, select the context in
`tests/wp-pest.php`:

```php
'wordpress' => [
    'context' => 'admin', // Default: 'front'. Applies to Code tests.
],
```

This defines `WP_ADMIN` before plugins load, including during fixture preparation.
The choice lasts for the PHP process; run front-end and admin bootstrap tests in
separate processes/configurations. Selecting a screen later cannot rerun plugin
initialization. Browser requests select their context through WordPress normally.

Capture callbacks that call `wp_send_json_success()`, `wp_send_json_error()`, or
`wp_die()` without terminating Pest:

```php
use CrossPeak\WpPest\WpDieException;
use function CrossPeak\WpPest\{captureAjax, interceptWpDie};

$response = captureAjax(fn () => my_ajax_handler());
expect($response->json())->toBe(['success' => true, 'data' => ['saved' => true]])
    ->and($response->status)->toBe(200);

$response = captureAjax(fn () => wp_die(-1, '', ['response' => 403]));
expect($response->output)->toBe('-1')->and($response->status)->toBe(403);

expect(fn () => interceptWpDie(fn () => my_nonce_protected_action()))
    ->toThrow(WpDieException::class);
```

`captureAjax()` temporarily makes `wp_doing_ajax()` true and intercepts WordPress
termination handlers. The response exposes `output`, `status`, and `termination`
(the `WpDieException`, or `null` when the callback returns normally). `json()`
strictly decodes the body and throws `JsonException` for malformed JSON.
Status defaults to 200 and observes WordPress's `status_header()` and `wp_die()`
response arguments. When PHP has already sent headers, WordPress may skip setting
a JSON response's status; these are callback tests, not HTTP requests.

The exception preserves the original `wpMessage`, `title`, and `arguments`;
`getMessage()` provides a readable message, including for `WP_Error` values.
`interceptWpDie()` throws this exception to the test. `captureAjax()` catches it
and records the AJAX body. Nonterminating calls with `exit => false` continue
through the original handler. Other exceptions propagate normally.

Temporary hooks, output buffers, headers, and the HTTP status are restored on exit,
including nested captures and failures (PHP headers can only be restored before
they are sent). No immutable AJAX/admin constants are set by these scoped helpers.
They do not dispatch an action, modify request data, or bypass nonce/capability
checks; prepare the request and user explicitly. A direct `exit` or `die` outside
`wp_die()` still terminates the process.

## Mail capture and scoped filters

Capture `wp_mail()` attempts during a callback without invoking PHPMailer or an
external mail transport:

```php
use CrossPeak\WpPest\MailMessage;
use function CrossPeak\WpPest\captureMail;

$mail = captureMail(fn () => send_my_notification());
$mail->assertCount(1)
    ->assertSentTo('reader@example.test')
    ->assertSubject('Submission received')
    ->assertBodyContains('Thank you')
    ->assertAttachment($path);

// Require all conditions to match the same message.
$mail->assertSent(fn (MailMessage $message) =>
    $message->hasRecipient('reader@example.test') &&
    $message->subject === 'Submission received'
);

captureMail(fn () => run_without_notifications())->assertNothingSent();
```

`$mail->messages` contains immutable `MailMessage` objects with `to`, `subject`,
`body`, and `attachments`. Their `attributes` retain the original `pre_wp_mail`
arguments, including headers. Recipient matching handles comma-separated lists,
arrays, display names, and email casing. Subject assertions match exactly; body
assertions look for a substring; attachment assertions match a path.

These are attempted messages before WordPress validates recipients, processes
headers, or accesses attachment files. The recorder simulates a successful send
by short-circuiting `pre_wp_mail`; it does not verify deliverability or file
contents. Earlier filters still run, but their return value is overridden during
capture. Each convenience assertion searches any captured message; use
`assertSent()` to match several conditions on one message. An outer capture
includes messages attempted by nested captures. Filters restore immediately on
exit, and exceptions from the callback propagate normally.

Temporarily add or override a filter for one operation:

```php
use function CrossPeak\WpPest\withFilter;

$value = withFilter(
    'my_plugin_can_decrypt',
    '__return_false',
    fn () => render_my_entry()
);

withFilter('my_plugin_setting', $override, fn () => check_settings(), 10, 2);
```

The last two arguments are WordPress's priority and accepted argument count.
`withFilter()` returns the callback's result and restores the hook's complete
original callback registry, including priorities and accepted argument counts,
even after nested scopes or exceptions. Changes to other hooks remain intact.
These are in-process Code-test helpers; browser requests run in another process.

## CI overrides

- `WP_PEST_TARGET`
- `WP_PEST_TESTS`
- `WP_PEST_URL`
- `WP_PEST_PLUGIN_PATH`
- `WP_PEST_WORDPRESS_VERSION`
- `WP_PEST_MATRIX`
- `WP_PEST_BROWSER_HOST` (host used to reach a plugin browser sandbox; defaults to `127.0.0.1`)
