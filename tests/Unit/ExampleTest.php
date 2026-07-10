<?php

declare(strict_types=1);

it('does not require generated tests to bootstrap WordPress manually', function (): void {
    $examples = [
        __DIR__.'/../../examples/tests/site/CodeTest.php',
        __DIR__.'/../../examples/tests/plugin/CodeTest.php',
    ];

    foreach ($examples as $example) {
        expect(file_get_contents($example))->not->toContain('bootstrapWordPress');
    }
});
