<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Cli;

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use CrossPeak\WpPest\Sandbox\SandboxManager;
use CrossPeak\WpPest\Support\Process;
use CrossPeak\WpPest\Support\ProcessRunner;
use CrossPeak\WpPest\Support\SiteDetector;

final class Application
{
    public function __construct(private readonly ProcessRunner $process = new Process) {}

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $workingDirectory = getcwd() ?: '.';

        try {
            return match ($command) {
                'init' => $this->init($workingDirectory, array_slice($argv, 2)),
                'doctor' => $this->doctor($workingDirectory),
                'run' => $this->runPest($workingDirectory, array_slice($argv, 2)),
                'sandbox:start' => $this->sandbox($workingDirectory, 'start', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:stop' => $this->sandbox($workingDirectory, 'stop', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:reset' => $this->sandbox($workingDirectory, 'reset', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:destroy' => $this->sandbox($workingDirectory, 'destroy', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                default => $this->help(),
            };
        } catch (ValidationException $exception) {
            fwrite(STDERR, $exception->getMessage().PHP_EOL);

            return 1;
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function init(string $workingDirectory, array $arguments): int
    {
        $target = $this->option($arguments, 'target') ?? 'site';

        if (! in_array($target, ['site', 'plugin'], true)) {
            throw new ValidationException(['init --target must be either "site" or "plugin".']);
        }

        $config = $workingDirectory.DIRECTORY_SEPARATOR.'wp-pest.php';
        $pest = $workingDirectory.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Pest.php';
        $phpunit = $workingDirectory.DIRECTORY_SEPARATOR.'phpunit.xml.dist';
        $gitignore = $workingDirectory.DIRECTORY_SEPARATOR.'.gitignore';

        if (! is_file($config)) {
            file_put_contents($config, file_get_contents($this->initTemplate($target)));
            echo "Created wp-pest.php\n";
        }

        if (! is_dir(dirname($pest))) {
            mkdir(dirname($pest), 0775, true);
        }

        if (! is_file($pest)) {
            file_put_contents($pest, file_get_contents(__DIR__.'/../../examples/tests/Pest.php'));
            echo "Created tests/Pest.php\n";
        }

        if (! is_file($phpunit) && ! is_file($workingDirectory.DIRECTORY_SEPARATOR.'phpunit.xml')) {
            file_put_contents($phpunit, file_get_contents(__DIR__.'/../../examples/phpunit.xml.dist'));
            echo "Created phpunit.xml.dist\n";
        }

        $this->copyExampleTests($workingDirectory, $target);
        $this->appendGitignore($gitignore, [".wp-pest/\n", "tests/Browser/Screenshots/\n"]);

        return 0;
    }

    private function doctor(string $workingDirectory): int
    {
        $config = (new ConfigLoader)->load($workingDirectory);
        $errors = [];

        if ($config->target() === 'site' && $config->hasCodeTests() && (new SiteDetector)->detect($config->sitePath() ?? $workingDirectory) === null) {
            $errors[] = 'Unable to locate wp-load.php for site code tests.';
        }

        if ($config->target() === 'plugin' && $config->pluginPath() !== null && ! is_dir($this->absolutePath($workingDirectory, $config->pluginPath()))) {
            $errors[] = 'plugin.path does not exist or is not a directory.';
        }

        if ($config->hasBrowserTests() && ! $this->commandExists('node')) {
            $errors[] = 'node is required for Pest browser testing.';
        }

        if ($config->target() === 'plugin' && $config->hasBrowserTests() && ! $this->commandExists('docker')) {
            $errors[] = 'docker is required for plugin browser sandboxes.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                fwrite(STDERR, "[fail] {$error}\n");
            }

            return 1;
        }

        echo "wp-pest configuration looks valid.\n";

        return 0;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runPest(string $workingDirectory, array $arguments): int
    {
        $environment = $this->captureEnvironment([
            'WP_PEST_TARGET',
            'WP_PEST_TESTS',
            'WP_PEST_WORDPRESS_PATH',
            'WP_PEST_AUTO_BOOTSTRAP_WORDPRESS',
            'WP_PEST_MATRIX',
            'WP_PEST_ACTIVE_URL',
        ]);

        try {
            return $this->runPestWithEnvironment($workingDirectory, $arguments);
        } finally {
            $this->restoreEnvironment($environment);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runPestWithEnvironment(string $workingDirectory, array $arguments): int
    {
        $target = $this->option($arguments, 'target');
        $tests = $this->option($arguments, 'tests');
        $pestArguments = $this->pestArguments($workingDirectory, $arguments, $tests);

        if ($target !== null) {
            putenv("WP_PEST_TARGET={$target}");
        }

        if ($tests !== null) {
            putenv("WP_PEST_TESTS={$tests}");
        }

        $config = (new ConfigLoader)->load($workingDirectory);

        if ($config->target() === 'plugin' && $config->hasCodeTests()) {
            $fixture = (new PluginFixture($config, $workingDirectory))->ensure();
            putenv("WP_PEST_WORDPRESS_PATH={$fixture}/wordpress");
        }

        putenv('WP_PEST_AUTO_BOOTSTRAP_WORDPRESS='.($config->hasCodeTests() ? '1' : '0'));

        $matrix = $this->option($arguments, 'matrix') ?? getenv('WP_PEST_MATRIX') ?: null;

        if ($config->target() === 'plugin' && $config->hasBrowserTests()) {
            $manager = new SandboxManager($config, $workingDirectory);
            $exitCode = 0;

            foreach ($manager->matrixNames($matrix) as $name) {
                $start = $manager->start($name);
                if ($start !== 0) {
                    return $start;
                }

                putenv("WP_PEST_MATRIX={$name}");
                putenv('WP_PEST_ACTIVE_URL='.$manager->url($name));
                $exitCode = $this->process->run(array_merge([$this->pestBinary($workingDirectory)], $pestArguments), $workingDirectory, true);
                $manager->stop($name);

                if ($exitCode !== 0) {
                    return $exitCode;
                }
            }

            return $exitCode;
        }

        return $this->process->run(array_merge([$this->pestBinary($workingDirectory)], $pestArguments), $workingDirectory, true);
    }

    private function sandbox(string $workingDirectory, string $action, ?string $selection): int
    {
        $config = (new ConfigLoader)->load($workingDirectory);
        $manager = new SandboxManager($config, $workingDirectory);
        $exitCode = 0;

        foreach ($manager->matrixNames($selection) as $name) {
            $exitCode = $manager->{$action}($name);

            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        return $exitCode;
    }

    private function help(): int
    {
        echo "Usage: wp-pest <command>\n";
        echo "Commands: init [--target=plugin|site], doctor, run, sandbox:start, sandbox:stop, sandbox:reset, sandbox:destroy\n";

        return 0;
    }

    private function commandExists(string $command): bool
    {
        exec('command -v '.escapeshellarg($command), $output, $exitCode);

        return $exitCode === 0;
    }

    private function pestBinary(string $workingDirectory): string
    {
        return $workingDirectory.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest';
    }

    private function absolutePath(string $workingDirectory, string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $workingDirectory.DIRECTORY_SEPARATOR.$path;
    }

    private function initTemplate(string $target): string
    {
        return __DIR__."/../../examples/wp-pest.{$target}.php";
    }

    private function copyExampleTests(string $workingDirectory, string $target): void
    {
        $examples = [
            'code' => [
                __DIR__."/../../examples/tests/{$target}/CodeTest.php",
                $workingDirectory.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Code'.DIRECTORY_SEPARATOR.ucfirst($target).'Test.php',
            ],
            'browser' => [
                __DIR__."/../../examples/tests/{$target}/BrowserTest.php",
                $workingDirectory.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Browser'.DIRECTORY_SEPARATOR.ucfirst($target).'Test.php',
            ],
        ];

        foreach ($examples as [$source, $destination]) {
            if (is_file($destination)) {
                continue;
            }

            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0775, true);
            }

            copy($source, $destination);
            echo 'Created '.str_replace($workingDirectory.DIRECTORY_SEPARATOR, '', $destination).PHP_EOL;
        }
    }

    /**
     * @param  list<string>  $entries
     */
    private function appendGitignore(string $gitignore, array $entries): void
    {
        $contents = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
        $updated = false;

        foreach ($entries as $entry) {
            if (str_contains($contents, $entry)) {
                continue;
            }

            $contents .= $entry;
            $updated = true;
        }

        if ($updated) {
            file_put_contents($gitignore, $contents);
            echo "Updated .gitignore\n";
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function option(array $arguments, string $name): ?string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, "--{$name}=")) {
                return substr($argument, strlen($name) + 3);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function pestArguments(string $workingDirectory, array $arguments, ?string $tests): array
    {
        $arguments = array_values(array_filter(
            $arguments,
            fn (string $argument): bool => ! str_starts_with($argument, '--target=')
                && ! str_starts_with($argument, '--tests=')
                && ! str_starts_with($argument, '--matrix=')
        ));

        if ($tests === null || $tests === '') {
            return $arguments;
        }

        $paths = [];

        foreach (array_map('trim', explode(',', $tests)) as $test) {
            $path = match ($test) {
                'code' => 'tests/Code',
                'browser' => 'tests/Browser',
                default => null,
            };

            if ($path !== null && is_dir($workingDirectory.DIRECTORY_SEPARATOR.$path)) {
                $paths[] = $path;
            }
        }

        return array_merge($paths, $arguments);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string|false>
     */
    private function captureEnvironment(array $names): array
    {
        $environment = [];

        foreach ($names as $name) {
            $environment[$name] = getenv($name);
        }

        return $environment;
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function restoreEnvironment(array $environment): void
    {
        foreach ($environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}
