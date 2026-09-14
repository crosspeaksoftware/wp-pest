<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Support\ProcessRunner;
use RuntimeException;

final class DockerCompose
{
    /** @return list<string> */
    public static function command(ProcessRunner $process): array
    {
        foreach ([
            [['docker', 'compose', 'version'], ['docker', 'compose'], '/^Docker Compose version\b/i'],
            [['docker-compose', 'version'], ['docker-compose'], '/^docker-compose version\b/i'],
        ] as [$probe, $command, $pattern]) {
            try {
                $version = $process->capture($probe);
            } catch (RuntimeException) {
                continue;
            }

            if (preg_match($pattern, trim($version)) === 1) {
                return $command;
            }
        }

        throw new ValidationException([
            'Docker Compose is required for plugin browser sandboxes. Install the Docker Compose plugin (`docker compose`) or standalone `docker-compose`.',
        ]);
    }
}
