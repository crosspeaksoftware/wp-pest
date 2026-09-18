<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use CrossPeak\WpPest\Browser\WpPage;
use JsonException;
use LogicException;
use Pest\Browser\Configuration;
use RuntimeException;

/**
 * Evaluate PHP in the active wp-pest plugin sandbox and return its JSON-compatible result.
 *
 * The supplied code runs inside a closure with the WordPress database global available.
 * Return a scalar or array from the code to receive it in the test process.
 *
 * @throws JsonException When the sandbox result is not valid JSON.
 * @throws RuntimeException When WP-CLI does not return a result marker.
 */
function wpEval(string $code): mixed
{
    $marker = '__WP_PEST_RESULT_'.bin2hex(random_bytes(12)).'__';
    $script = 'global $wpdb; $result = (static function () { global $wpdb; '."\n".$code."\n".'})(); echo '.var_export("\n{$marker}", true).' . wp_json_encode($result);';
    $output = wp_pest()->wpCli(['eval', $script]);
    $position = strrpos($output, $marker);

    if ($position === false) {
        throw new RuntimeException('WP-CLI did not return the expected wpEval result marker. Output: '.$output);
    }

    return json_decode(trim(substr($output, $position + strlen($marker))), true, 512, JSON_THROW_ON_ERROR);
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
