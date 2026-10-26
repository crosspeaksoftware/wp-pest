<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use RuntimeException;

/** A wp_die() termination intercepted while testing a WordPress callback. */
final class WpDieException extends RuntimeException
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly mixed $wpMessage,
        public readonly string $title,
        public readonly array $arguments,
    ) {
        $message = is_object($wpMessage) && method_exists($wpMessage, 'get_error_message')
            ? $wpMessage->get_error_message()
            : (is_scalar($wpMessage) ? (string) $wpMessage : 'WordPress terminated the request.');
        parent::__construct($message);
    }
}
