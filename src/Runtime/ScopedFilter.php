<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use InvalidArgumentException;
use RuntimeException;

final class ScopedFilter
{
    public static function run(string $hook, callable $filter, callable $callback, int $priority = 10, int $acceptedArgs = 1): mixed
    {
        if (! function_exists('add_filter')) {
            throw new RuntimeException('WordPress must be bootstrapped before using scoped filters.');
        }
        if ($acceptedArgs < 0) {
            throw new InvalidArgumentException('Accepted filter arguments must be zero or greater.');
        }
        $saved = isset($GLOBALS['wp_filter'][$hook]) ? clone $GLOBALS['wp_filter'][$hook] : null;
        try {
            add_filter($hook, $filter, $priority, $acceptedArgs);

            return $callback();
        } finally {
            if ($saved !== null) {
                $GLOBALS['wp_filter'][$hook] = $saved;
            } else {
                unset($GLOBALS['wp_filter'][$hook]);
            }
        }
    }
}
