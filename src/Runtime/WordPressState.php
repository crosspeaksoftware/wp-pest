<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use SplObjectStorage;

/** Snapshots WordPress request state without cloning plugin services. */
final class WordPressState
{
    private const GLOBALS = [
        '_GET', '_POST', '_REQUEST', '_FILES', '_COOKIE', '_SERVER',
        'wp_filter', 'wp_actions', 'wp_filters', 'wp_current_filter',
        'current_user', 'user_ID', 'userdata', 'user_login', 'user_email',
        'user_level', 'user_identity', 'current_screen', 'authordata', 'post',
        'wp_query', 'wp_the_query', 'wp', 'wp_scripts', 'wp_styles',
        'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages',
    ];

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct()
    {
        $copies = new SplObjectStorage;
        $copy = static function (object $value) use ($copies): object {
            if (! isset($copies[$value])) {
                $copies[$value] = clone $value;
            }

            return $copies[$value];
        };
        foreach (self::GLOBALS as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $value = $GLOBALS[$key];
                if ($key === 'wp_filter' && is_array($value)) {
                    $value = array_map(static fn ($hook) => is_object($hook) ? $copy($hook) : $hook, $value);
                } elseif (is_object($value)) {
                    $value = $copy($value);
                    if (in_array($key, ['wp_scripts', 'wp_styles'], true) && isset($value->registered)) {
                        $value->registered = array_map(static fn ($item) => is_object($item) ? $copy($item) : $item, $value->registered);
                    }
                }
                $this->values[$key] = $value;
            }
        }
    }

    public function restore(): void
    {
        foreach (self::GLOBALS as $key) {
            if (array_key_exists($key, $this->values)) {
                $GLOBALS[$key] = $this->values[$key];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }
}
