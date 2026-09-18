<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Support;

use RuntimeException;

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

    public function capture(array $command, ?string $cwd = null): string
    {
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $error = '';
        $open = [$pipes[1], $pipes[2]];

        while ($open !== []) {
            $read = $open;
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $pipe) {
                $contents = stream_get_contents($pipe);
                if ($pipe === $pipes[1]) {
                    $output .= (string) $contents;
                } else {
                    $error .= (string) $contents;
                }

                if (feof($pipe)) {
                    $key = array_search($pipe, $open, true);
                    if ($key !== false) {
                        unset($open[$key]);
                    }
                }
            }
            $open = array_values($open);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $error) ?: 'Process failed with exit code '.$exitCode.'.');
        }

        return trim((string) $output);
    }
}
