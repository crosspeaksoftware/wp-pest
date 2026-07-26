<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Support;

interface ProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $cwd = null, bool $passthru = false): int;

    /**
     * @param  list<string>  $command
     */
    public function capture(array $command, ?string $cwd = null): string;
}
