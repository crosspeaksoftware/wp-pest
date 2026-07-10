<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Support;

final class Process implements ProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $previousDirectory = getcwd();

        if ($cwd !== null) {
            chdir($cwd);
        }

        if ($passthru) {
            passthru($escaped, $exitCode);
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }

            return (int) $exitCode;
        }

        exec($escaped, $output, $exitCode);

        if ($previousDirectory !== false) {
            chdir($previousDirectory);
        }

        foreach ($output as $line) {
            echo $line.PHP_EOL;
        }

        return (int) $exitCode;
    }
}
