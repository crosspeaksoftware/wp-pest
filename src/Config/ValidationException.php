<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Config;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(PHP_EOL, $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
