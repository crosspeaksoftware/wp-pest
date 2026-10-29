<?php

declare(strict_types=1);

use CrossPeak\WpPest\Runtime\SandboxEvaluator;
use CrossPeak\WpPest\Support\Process;

beforeEach(function (): void {
    $this->remotePlugins = $this->workspace.'/sandbox/wp-content/plugins';
    mkdir($this->remotePlugins.'/plugin-under-test/tests/fixtures', 0775, true);
    $plugins = var_export($this->remotePlugins, true);
    $this->evaluate = new SandboxEvaluator(static function (array $command) use ($plugins): string {
        $bootstrap = 'define("WP_PLUGIN_DIR", '.$plugins.'); $wpdb = (object) ["prefix" => "wp_"];';
        $bootstrap .= 'echo "plugin bootstrap output"; register_shutdown_function(static function () { echo "shutdown output"; });';

        // Match WP-CLI's stderr diagnostics regardless of the host php.ini settings.
        return (new Process)->capture([
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            '-d', 'error_reporting=-1',
            '-r', $bootstrap.$command[1],
        ]);
    });
});

it('round trips arguments without treating strings as PHP', function (): void {
    $input = [
        'text' => "quotes '\"; backslash \\; dollar \$args; newline\nUnicode 東京",
        'injection' => '"); throw new RuntimeException("injected"); //',
        'values' => [null, true, false, 0, 1.0, ['nested' => 'value']],
    ];
    expect($this->evaluate->evaluate('echo "fixture output"; return [$args, $wpdb->prefix];', $input))
        ->toBe([$input, 'wp_']);
});

it('keeps existing calls and scalar return values working', function (mixed $value): void {
    expect($this->evaluate->evaluate('return '.var_export($value, true).';'))->toBe($value);
})->with([null, false, true, 0, 'hello', 1.0]);

it('rejects input that cannot be encoded before running WP-CLI', function (): void {
    $called = false;
    $evaluator = new SandboxEvaluator(static function () use (&$called): string {
        $called = true;

        return '';
    });
    expect(fn () => $evaluator->evaluate('return null;', ['value' => INF]))->toThrow(JsonException::class)
        ->and($called)->toBeFalse();
});

it('reports return values that cannot be encoded', function (): void {
    expect(fn () => $this->evaluate->evaluate('return INF;'))->toThrow(RuntimeException::class, 'Inf and NaN');
});

it('reports missing result markers', function (): void {
    $evaluator = new SandboxEvaluator(static fn (): string => 'plugin exited early');
    expect(fn () => $evaluator->evaluate('return null;'))->toThrow(RuntimeException::class, 'plugin exited early');
});

it('requires fixtures from the sandbox copy and preserves file context and includes', function (): void {
    $source = $this->workspace.'/source';
    mkdir($source.'/tests/fixtures', 0775, true);
    $fixture = '/tests/fixtures/quoted\' fixture.php';
    file_put_contents($source.$fixture, '<?php throw new RuntimeException("Host copy must not execute");');
    $remote = $this->remotePlugins.'/plugin-under-test';
    file_put_contents($remote.'/tests/fixtures/value.php', '<?php return "included";');
    file_put_contents($remote.$fixture, '<?php return [$args["title"], $wpdb->prefix, __FILE__, require __DIR__."/value.php"];');

    expect($this->evaluate->evaluateFile($source.$fixture, ['title' => '東京'], $source))
        ->toBe(['東京', 'wp_', $remote.$fixture, 'included']);
});

it('rejects missing files and files outside the plugin before running WP-CLI', function (): void {
    $evaluator = new SandboxEvaluator(static function (): string {
        throw new LogicException('WP-CLI should not run.');
    });
    $source = $this->workspace.'/source';
    mkdir($source);
    file_put_contents($this->workspace.'/outside.php', '<?php return null;');
    symlink($this->workspace.'/outside.php', $source.'/linked.php');
    expect(fn () => $evaluator->evaluateFile($source.'/missing.php', [], $source))
        ->toThrow(InvalidArgumentException::class, 'not a readable file')
        ->and(fn () => $evaluator->evaluateFile($source.'/linked.php', [], $source))
        ->toThrow(InvalidArgumentException::class, 'inside the plugin');
});

it('retains the fixture filename in PHP errors', function (): void {
    $source = $this->workspace.'/source';
    mkdir($source);
    file_put_contents($source.'/broken.php', '<?php return 1;');
    $remote = $this->remotePlugins.'/plugin-under-test/broken.php';
    file_put_contents($remote, '<?php this is invalid PHP;');
    expect(fn () => $this->evaluate->evaluateFile($source.'/broken.php', [], $source))
        ->toThrow(RuntimeException::class, $remote);
});
