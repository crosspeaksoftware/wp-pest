<?php

declare(strict_types=1);

use CrossPeak\WpPest\Runtime\WordPressState;
use CrossPeak\WpPest\Support\Process;

it('runs all cleanup callbacks and restores state even when cleanup throws', function (bool $transactions): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', '<?php return '.var_export([
        'target' => 'site',
        'database' => ['transactions' => $transactions],
    ], true).';');
    $result = (new Process)->capture([
        PHP_BINARY, __DIR__.'/../fixtures/lifecycle.php', __DIR__.'/../../vendor/autoload.php',
    ], $this->workspace);

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'rows' => $transactions ? 0 : 1,
        'post' => ['original' => 'value'],
        'user' => 42,
        'order' => ['second', 'first'],
        'error' => 'cleanup failed',
        'cache_flushed' => true,
    ]);
})->with([true, false]);

it('restores mutable hook and asset registries and preserves query aliases', function (): void {
    $original = new WordPressState;
    try {
        $GLOBALS['wp_filter'] = ['probe' => (object) ['callbacks' => ['original']]];
        $GLOBALS['wp_scripts'] = (object) ['registered' => ['probe' => (object) ['extra' => ['original']]]];
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = (object) ['query_vars' => ['original']];
        unset($GLOBALS['current_screen']);
        $state = new WordPressState;
        $GLOBALS['wp_filter']['probe']->callbacks = [];
        $GLOBALS['wp_scripts']->registered['probe']->extra = [];
        $GLOBALS['wp_query']->query_vars = [];
        $GLOBALS['current_screen'] = (object) ['id' => 'dashboard'];
        $state->restore();

        expect($GLOBALS['wp_filter']['probe']->callbacks)->toBe(['original'])
            ->and($GLOBALS['wp_scripts']->registered['probe']->extra)->toBe(['original'])
            ->and($GLOBALS['wp_query']->query_vars)->toBe(['original'])
            ->and($GLOBALS['wp_query'])->toBe($GLOBALS['wp_the_query'])
            ->and(isset($GLOBALS['current_screen']))->toBeFalse();
    } finally {
        $original->restore();
    }
});
