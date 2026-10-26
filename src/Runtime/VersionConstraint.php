<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use InvalidArgumentException;

final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        if (preg_match('/^(>=|<=|!=|==|>|<|=)?\s*(\d+(?:\.\d+)*(?:[-+._][0-9A-Za-z.-]+)?)$/', trim($constraint), $parts) !== 1) {
            throw new InvalidArgumentException('Use one version comparison such as >=6.9 or <3.0: '.$constraint);
        }
        $operator = $parts[1] ?: '==';

        return version_compare($version, $parts[2], $operator === '=' ? '==' : $operator);
    }
}
