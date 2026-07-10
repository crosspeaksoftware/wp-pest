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

it('uses WordPress-friendly browser assertions in generated tests', function (): void {
    $examples = [
        __DIR__.'/../../examples/tests/site/BrowserTest.php',
        __DIR__.'/../../examples/tests/plugin/BrowserTest.php',
    ];

    foreach ($examples as $example) {
        expect(file_get_contents($example))
            ->not->toContain('assertNoSmoke')
            ->not->toContain('assertSeeAnythingIn')
            ->not->toContain("assertPresent('body')")
            ->toContain("assertSourceHas('<body')")
            ->toContain('assertNoJavaScriptErrors');
    }
});
