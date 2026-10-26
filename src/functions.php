<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use CrossPeak\WpPest\Browser\WpPage;
use CrossPeak\WpPest\Runtime\AdminScreen;
use CrossPeak\WpPest\Runtime\Factory;
use CrossPeak\WpPest\Runtime\ResponseCapture;
use CrossPeak\WpPest\Runtime\SandboxEvaluator;
use CrossPeak\WpPest\Runtime\ScopedFilter;
use JsonException;
use LogicException;
use Pest\Browser\Configuration;
use RuntimeException;

/**
 * Evaluate PHP in the active wp-pest plugin sandbox and return its JSON-compatible result.
 *
 * The supplied code runs inside a closure with $args and the WordPress database global available.
 * Return a scalar or array from the code to receive it in the test process.
 *
 * @param  array<array-key, mixed>  $arguments  JSON-compatible input exposed as $args.
 *
 * @throws JsonException When arguments cannot be encoded or the response cannot be decoded.
 * @throws RuntimeException When sandbox execution fails or WP-CLI does not return result markers.
 */
function wpEval(string $code, array $arguments = []): mixed
{
    return (new SandboxEvaluator(wp_pest()->wpCli(...)))->evaluate($code, $arguments);
}

/**
 * Require a plugin-local PHP fixture inside the active sandbox with $args and $wpdb available.
 *
 * The file executes from the sandbox's plugin directory, preserving __DIR__ and relative includes.
 * Return a JSON-compatible value from the fixture to receive it in the test process.
 *
 * @param  array<array-key, mixed>  $arguments
 */
function wpEvalFile(string $path, array $arguments = []): mixed
{
    $runtime = wp_pest();
    $pluginPath = $runtime->pluginPath();
    if ($runtime->target() !== 'plugin' || $pluginPath === null) {
        throw new LogicException('wpEvalFile() requires a plugin browser sandbox.');
    }

    return (new SandboxEvaluator($runtime->wpCli(...)))->evaluateFile($path, $arguments, $pluginPath);
}

/**
 * Log in to the active browser sandbox as its seeded administrator.
 *
 * @return WpPage The Pest Browser page, allowing further chained interactions.
 *
 * @throws LogicException When Pest Browser is unavailable or no site URL is configured.
 */
function loginAsAdmin(): WpPage
{
    if (! class_exists(Configuration::class)) {
        throw new LogicException('loginAsAdmin() requires pestphp/pest-plugin-browser.');
    }

    $runtime = wp_pest();

    if ($runtime->target() !== 'plugin') {
        throw new LogicException('loginAsAdmin() is only available for wp-pest plugin browser sandboxes.');
    }

    $url = $runtime->url();

    if ($url === null || $url === '') {
        throw new LogicException('loginAsAdmin() requires an active WordPress browser URL.');
    }

    $visit = 'visit';

    $page = new WpPage(
        $visit($runtime->urlFor('/wp-admin/')),
        static fn (string $path): string => wp_pest()->urlFor($path),
    );

    if (str_contains($page->url(), '/wp-login.php')) {
        $page->fill('#user_login', 'admin')
            ->fill('#user_pass', 'password')
            ->click('#wp-submit');
    }

    $page->assertPathBeginsWith('/wp-admin')
        ->assertSee('Dashboard');

    return $page;
}

/**
 * Access lightweight WordPress data factories for code tests.
 */
function factory(): Factory
{
    return wp_pest()->factory();
}

/**
 * Create and authenticate an administrator for a code test.
 */
function actingAsAdministrator(): int
{
    return wp_pest()->actingAsAdministrator();
}

/** Capture printed output, closing any buffers opened by the callback on failure. */
function captureOutput(callable $callback): string
{
    $level = ob_get_level();
    ob_start();
    try {
        $callback();
        while (ob_get_level() > $level + 1) {
            if (! ob_end_flush()) {
                throw new RuntimeException('The callback opened an output buffer that cannot be removed.');
            }
        }
        if (ob_get_level() !== $level + 1) {
            throw new RuntimeException('The callback closed the captureOutput() buffer.');
        }

        return (string) ob_get_contents();
    } finally {
        while (ob_get_level() > $level) {
            if (! ob_end_clean()) {
                break;
            }
        }
    }
}

/** Set an admin screen for this test, or restore it after the supplied callback. */
function onAdminScreen(string $screen, ?callable $callback = null): mixed
{
    return AdminScreen::run($screen, $callback);
}

/** Run a callback with terminating wp_die() calls converted to WpDieException. */
function interceptWpDie(callable $callback): mixed
{
    return ResponseCapture::intercept($callback);
}

/** Capture an AJAX callback's output, JSON, status, and wp_die() termination. */
function captureAjax(callable $callback): AjaxResponse
{
    return ResponseCapture::ajax($callback);
}

/** Compatibility alias for browser login. Prefer loginAsAdmin(). */
function actingAsAdmin(): WpPage
{
    return loginAsAdmin();
}

/** Authenticate an existing user in the code-test process. */
function actingAs(int|object $user): int
{
    return wp_pest()->actingAs($user);
}

/** Create and authenticate a user with an installed WordPress role. */
function actingAsRole(string $role): int
{
    return wp_pest()->actingAsRole($role);
}

/** Clear the current code-test user. */
function actingAsGuest(): void
{
    wp_pest()->actingAsGuest();
}

/** Restore a hook's original callbacks immediately after the callback, including on failure. */
function withFilter(string $hook, callable $filter, callable $callback, int $priority = 10, int $acceptedArgs = 1): mixed
{
    return ScopedFilter::run($hook, $filter, $callback, $priority, $acceptedArgs);
}

/** Capture attempted wp_mail calls and simulate success without invoking a mail transport. */
function captureMail(callable $callback): MailRecorder
{
    $messages = [];
    withFilter('pre_wp_mail', static function ($result, array $attributes) use (&$messages): bool {
        $messages[] = new MailMessage($attributes);

        return true;
    }, $callback, PHP_INT_MAX, 2);

    return new MailRecorder($messages);
}
