<?php

declare(strict_types=1);

use CrossPeak\WpPest\Cli\Application;
use CrossPeak\WpPest\Support\ProcessRunner;

it('initializes site mode by default', function (): void {
    file_put_contents($this->workspace.'/.gitignore', 'dist/');
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    $config = require $this->workspace.'/wp-pest.php';

    expect($exitCode)->toBe(0)
        ->and($config['target'])->toBe('site')
        ->and($config)->not->toHaveKeys(['plugin', 'plugins', 'sandbox', 'wordpress'])
        ->and($this->workspace.'/tests/Pest.php')->toBeFile()
        ->and(file_get_contents($this->workspace.'/tests/Pest.php'))->toContain('bootstrapWordPress')
        ->and(file_get_contents($this->workspace.'/tests/Pest.php'))->toContain('browser()->timeout(60_000)')
        ->and($this->workspace.'/phpunit.xml.dist')->toBeFile()
        ->and(file_get_contents($this->workspace.'/phpunit.xml.dist'))->toContain('<testsuite name="Code">')
        ->and(file_get_contents($this->workspace.'/phpunit.xml.dist'))->toContain('<testsuite name="Browser">')
        ->and($this->workspace.'/tests/Code/SiteTest.php')->toBeFile()
        ->and($this->workspace.'/tests/Browser/SiteTest.php')->toBeFile()
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toStartWith("dist/\n.wp-pest/\n")
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toContain('.wp-pest/')
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toContain('.phpunit.cache/')
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toContain('tests/Browser/Screenshots/');
});

it('initializes plugin mode when requested', function (): void {
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init', '--target=plugin']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    $config = require $this->workspace.'/wp-pest.php';

    expect($exitCode)->toBe(0)
        ->and($config['target'])->toBe('plugin')
        ->and($config)->not->toHaveKey('plugin')
        ->and($config['matrix']['wordpress'])->toBe(['^6.9', '^7.0'])
        ->and($config['matrix']['profiles']['default']['tests'])->toBe(['code', 'browser'])
        ->and($this->workspace.'/tests/Code/PluginTest.php')->toBeFile()
        ->and($this->workspace.'/tests/Browser/PluginTest.php')->toBeFile();
});

it('rejects invalid init targets', function (): void {
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init', '--target=other']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    expect($exitCode)->toBe(1)
        ->and($this->workspace.'/wp-pest.php')->not->toBeFile();
});

it('runs only code tests when requested', function (): void {
    prepareRunWorkspace($this->workspace);
    $process = new RecordingProcess;
    $previous = getcwd();
    chdir($this->workspace);

    try {
        $exitCode = (new Application($process))->run(['wp-pest', 'run', '--tests=code']);
    } finally {
        chdir($previous);
    }

    expect($exitCode)->toBe(0)
        ->and($process->commands[0])->toBe([PHP_BINARY, $this->workspace.'/vendor/bin/pest', 'tests/Code']);
});

it('runs only browser tests when requested', function (): void {
    prepareRunWorkspace($this->workspace);
    $process = new RecordingProcess;
    $previous = getcwd();
    chdir($this->workspace);

    try {
        $exitCode = (new Application($process))->run(['wp-pest', 'run', '--tests=browser']);
    } finally {
        chdir($previous);
    }

    expect($exitCode)->toBe(0)
        ->and($process->commands[0])->toBe([PHP_BINARY, $this->workspace.'/vendor/bin/pest', 'tests/Browser']);
});

it('translates the browser testsuite into an explicit test path', function (): void {
    prepareRunWorkspace($this->workspace);
    $process = new RecordingProcess;
    $previous = getcwd();
    chdir($this->workspace);

    try {
        $exitCode = (new Application($process))->run(['wp-pest', 'run', '--testsuite=Browser']);
    } finally {
        chdir($previous);
    }

    expect($exitCode)->toBe(0)
        ->and($process->commands[0])->toBe([PHP_BINARY, $this->workspace.'/vendor/bin/pest', 'tests/Browser']);
});

it('does not forward wp-pest options to pest', function (): void {
    prepareRunWorkspace($this->workspace);
    $process = new RecordingProcess;
    $previous = getcwd();
    chdir($this->workspace);

    try {
        $exitCode = (new Application($process))->run(['wp-pest', 'run', '--target=site', '--tests=code', '--filter=home']);
    } finally {
        chdir($previous);
    }

    expect($exitCode)->toBe(0)
        ->and($process->commands[0])->toBe([PHP_BINARY, $this->workspace.'/vendor/bin/pest', 'tests/Code', '--filter=home']);
});

it('explains that socket_create_listen() is required before browser tests start', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['browser'],
    'site' => ['url' => 'https://example.test'],
];
PHP);

    $workspace = $this->workspace;
    $runWithoutSockets = static function (array $arguments) use ($workspace): array {
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $script = 'require '.var_export($autoload, true).'; chdir('.var_export($workspace, true).'); exit((new \\CrossPeak\\WpPest\\Cli\\Application)->run('.var_export(['wp-pest', ...$arguments], true).'));';
        exec(escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($script).' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    };

    [$doctorExitCode, $doctorOutput] = $runWithoutSockets(['doctor']);
    [$runExitCode, $runOutput] = $runWithoutSockets(['run', '--tests=browser']);

    expect($doctorExitCode)->toBe(1)
        ->and($doctorOutput)->toContain('socket_create_listen()', 'Code-only tests do not require it')
        ->and($runExitCode)->toBe(1)
        ->and($runOutput)->toContain('socket_create_listen()', 'Code-only tests do not require it');
});

it('allows Code-only runs without socket functions', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['code', 'browser'],
    'site' => ['url' => 'https://example.test'],
];
PHP);
    mkdir($this->workspace.'/vendor/bin', 0775, true);
    file_put_contents($this->workspace.'/vendor/bin/pest', "<?php\nexit(0);\n");

    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = 'require '.var_export($autoload, true).'; chdir('.var_export($this->workspace, true).'); exit((new \\CrossPeak\\WpPest\\Cli\\Application)->run(["wp-pest", "run", "--tests=code"]));';
    exec(escapeshellarg(PHP_BINARY).' -n -r '.escapeshellarg($script).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->not->toContain('socket_create_listen()');
});

function prepareRunWorkspace(string $workspace): void
{
    mkdir($workspace.'/tests/Code', 0775, true);
    mkdir($workspace.'/tests/Browser', 0775, true);
    mkdir($workspace.'/vendor/bin', 0775, true);
    file_put_contents($workspace.'/wp-load.php', '<?php');
    file_put_contents($workspace.'/vendor/bin/pest', '');
    file_put_contents($workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['code', 'browser'],
    'site' => ['url' => 'https://example.test'],
];
PHP);
}

final class RecordingProcess implements ProcessRunner
{
    /**
     * @var list<list<string>>
     */
    public array $commands = [];

    public function run(array $command, ?string $cwd = null, bool $passthru = false): int
    {
        $this->commands[] = $command;

        return 0;
    }

    public function capture(array $command, ?string $cwd = null): string
    {
        $this->commands[] = $command;

        return '';
    }
}
