<?php

declare(strict_types=1);

use function CrossPeak\WpPest\captureOutput;

it('captures nested output without touching the callers buffer', function (): void {
    $level = ob_get_level();
    ob_start();
    try {
        echo 'outer';
        $output = captureOutput(static function (): void {
            echo 'first';
            ob_start(static fn (string $value): string => strtoupper($value));
            echo 'second';
        });
        expect($output)->toBe('firstSECOND')
            ->and(ob_get_contents())->toBe('outer')
            ->and(ob_get_level())->toBe($level + 1);
    } finally {
        ob_end_clean();
    }
});

it('discards output and closes nested buffers when the callback fails', function (): void {
    $level = ob_get_level();
    $error = new RuntimeException('original failure');
    try {
        captureOutput(static function () use ($error): void {
            echo 'discard';
            ob_start();
            echo 'nested discard';
            throw $error;
        });
        $this->fail('Expected the original exception.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($error)->and(ob_get_level())->toBe($level);
    }
});

it('reports a callback closing its capture buffer', function (): void {
    $level = ob_get_level();
    expect(fn () => captureOutput(static fn () => ob_end_clean()))
        ->toThrow(RuntimeException::class, 'closed the captureOutput() buffer');
    expect(ob_get_level())->toBe($level);
});
