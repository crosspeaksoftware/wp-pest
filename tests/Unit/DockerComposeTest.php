<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Sandbox\DockerCompose;
use CrossPeak\WpPest\Support\ProcessRunner;

it('prefers the Docker Compose CLI plugin when available', function (): void {
    $process = new ComposeProbeProcess(['docker compose version' => 'Docker Compose version v2.29.0']);

    expect(DockerCompose::command($process))->toBe(['docker', 'compose']);
});

it('falls back to standalone docker-compose when the CLI plugin is unavailable', function (): void {
    $process = new ComposeProbeProcess([
        'docker compose version' => new RuntimeException('Compose plugin not installed.'),
        'docker-compose version' => 'docker-compose version 1.29.2',
    ]);

    expect(DockerCompose::command($process))->toBe(['docker-compose']);
});

it('reports a helpful error when neither Compose command is available', function (): void {
    $process = new ComposeProbeProcess([
        'docker compose version' => new RuntimeException('Compose plugin not installed.'),
        'docker-compose version' => new RuntimeException('docker-compose not installed.'),
    ]);

    DockerCompose::command($process);
})->throws(ValidationException::class, 'Docker Compose is required');

final class ComposeProbeProcess implements ProcessRunner
{
    /** @param array<string, string|RuntimeException> $responses */
    public function __construct(private readonly array $responses) {}

    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        return 0;
    }

    public function capture(array $command, ?string $cwd = null): string
    {
        $key = implode(' ', $command);
        $response = $this->responses[$key] ?? new RuntimeException('Unexpected probe.');

        if ($response instanceof RuntimeException) {
            throw $response;
        }

        return $response;
    }
}
