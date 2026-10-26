<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

final readonly class AjaxResponse
{
    public function __construct(
        public string $output,
        public int $status,
        public ?WpDieException $termination,
    ) {}

    /** Decode strictly: unexpected HTML or empty output must not silently pass. */
    public function json(): mixed
    {
        return json_decode($this->output, true, 512, JSON_THROW_ON_ERROR);
    }
}
