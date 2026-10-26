<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use RuntimeException;

final class Constants
{
    /** @param array<string, mixed> $values */
    public static function apply(array $values): void
    {
        foreach ($values as $name => $value) {
            if (defined($name) && constant($name) !== $value) {
                throw new RuntimeException('Configured WordPress constant conflicts with an existing definition: '.$name);
            }
            if (! defined($name)) {
                define($name, $value);
            }
        }
    }

    /** Self-contained PHP for fixtures and containers which cannot autoload wp-pest.
     * @param  array<string, mixed>  $values
     */
    public static function code(array $values): string
    {
        if ($values === []) {
            return '';
        }

        return str_replace('__VALUES__', var_export($values, true), <<<'PHP'
foreach (__VALUES__ as $wpPestConstantName => $wpPestConstantValue) {
    if (defined($wpPestConstantName) && constant($wpPestConstantName) !== $wpPestConstantValue) {
        throw new \RuntimeException('Configured WordPress constant conflicts with an existing definition: '.$wpPestConstantName);
    }
    if (! defined($wpPestConstantName)) {
        define($wpPestConstantName, $wpPestConstantValue);
    }
}
unset($wpPestConstantName, $wpPestConstantValue);
PHP);
    }
}
