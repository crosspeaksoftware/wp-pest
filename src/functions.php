<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use CrossPeak\WpPest\Browser\WpPage;
use CrossPeak\WpPest\Runtime\Factory;
use CrossPeak\WpPest\Runtime\SandboxEvaluator;
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
function actingAsAdmin(): WpPage
{
    if (! class_exists(Configuration::class)) {
        throw new LogicException('actingAsAdmin() requires pestphp/pest-plugin-browser.');
    }

    $runtime = wp_pest();

    if ($runtime->target() !== 'plugin') {
        throw new LogicException('actingAsAdmin() is only available for wp-pest plugin browser sandboxes.');
    }

    $url = $runtime->url();

    if ($url === null || $url === '') {
        throw new LogicException('actingAsAdmin() requires an active WordPress browser URL.');
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
