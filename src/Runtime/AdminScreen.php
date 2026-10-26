<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use RuntimeException;

final class AdminScreen
{
    public static function load(): void
    {
        if (! defined('ABSPATH') || ! function_exists('wp_set_current_user')) {
            throw new RuntimeException('WordPress must be bootstrapped before loading the admin API.');
        }

        require_once ABSPATH.'wp-admin/includes/admin.php';
    }

    public static function run(string $screen, ?callable $callback = null): mixed
    {
        self::load();
        if (! function_exists('set_current_screen') || ! function_exists('get_current_screen')) {
            throw new RuntimeException('The WordPress admin screen API could not be loaded.');
        }
        $keys = ['current_screen', 'typenow', 'taxnow'];
        $saved = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $saved[$key] = $GLOBALS[$key];
            }
        }

        try {
            set_current_screen($screen);
            if ($callback !== null) {
                return $callback();
            }

            return get_current_screen();
        } finally {
            if ($callback !== null) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $saved)) {
                        $GLOBALS[$key] = $saved[$key];
                    } else {
                        unset($GLOBALS[$key]);
                    }
                }
            }
        }
    }
}
