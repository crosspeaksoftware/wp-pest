<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Cli;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Matrix\MatrixCombination;
use CrossPeak\WpPest\Sandbox\DockerCompose;
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
                'matrix:list' => $this->matrixList($workingDirectory),
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
        $this->appendGitignore($gitignore, [".wp-pest/\n", ".phpunit.cache/\n", "tests/Browser/Screenshots/\n"]);

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

        $hasBrowserTests = $config->hasBrowserTests();
        if ($config->target() === 'plugin') {
            $hasBrowserTests = array_filter(
                $config->matrixCombinations(),
                static fn (MatrixCombination $combination): bool => in_array('browser', $combination->tests(), true),
            ) !== [];
        }

        if ($hasBrowserTests && ! $this->commandExists('node')) {
            $errors[] = 'node is required for Pest browser testing.';
        }

        if ($hasBrowserTests && ! function_exists('socket_create_listen')) {
            $errors[] = $this->socketsExtensionMessage();
        }

        if ($config->target() === 'plugin' && $hasBrowserTests) {
            try {
                DockerCompose::command($this->process);
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
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
            'WP_PEST_MATRIX',
            'WP_PEST_ACTIVE_URL',
            'WP_PEST_RESOLVED_WORDPRESS_VERSION',
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
        if ($tests === null) {
            $suite = $this->option($arguments, 'testsuite');
            $suiteTests = $suite === null ? [] : array_map('strtolower', array_map('trim', explode(',', $suite)));
            if ($suiteTests !== [] && array_diff($suiteTests, ['code', 'browser']) === []) {
                $tests = implode(',', $suiteTests);
            }
        }
        $pestArguments = $this->pestArguments($workingDirectory, $arguments, $tests);

        if ($target !== null) {
            putenv("WP_PEST_TARGET={$target}");
        }

        $config = (new ConfigLoader)->load($workingDirectory);
        $matrix = $this->option($arguments, 'matrix') ?? getenv('WP_PEST_MATRIX') ?: null;

        if ($config->target() === 'plugin') {
            $manager = new SandboxManager($config, $workingDirectory);
            $matrixNames = $manager->matrixNames($matrix);

            foreach ($matrixNames as $name) {
                $entryTests = $this->selectedTests($this->matrixEntry($config, $name)->tests(), $tests);
                $this->assertBrowserRequirements($entryTests);
            }

            $exitCode = 0;

            foreach ($matrixNames as $name) {
                $entry = $this->matrixEntry($config, $name);
                $entryTests = $this->selectedTests($entry->tests(), $tests);
                if ($entryTests === []) {
                    continue;
                }

                putenv("WP_PEST_MATRIX={$name}");
                putenv('WP_PEST_TESTS='.implode(',', $entryTests));
                putenv('WP_PEST_ACTIVE_URL');

                $fixtureManager = new PluginFixture($config, $workingDirectory);
                $fixture = $fixtureManager->ensure();
                putenv("WP_PEST_WORDPRESS_PATH={$fixture}/wordpress");
                $resolved = $fixtureManager->resolvedWordpressVersion();
                putenv($resolved === null ? 'WP_PEST_RESOLVED_WORDPRESS_VERSION' : "WP_PEST_RESOLVED_WORDPRESS_VERSION={$resolved}");

                $browser = in_array('browser', $entryTests, true);
                if ($browser) {
                    $start = $manager->start($name, fresh: true);
                    if ($start !== 0) {
                        return $start;
                    }

                    putenv('WP_PEST_ACTIVE_URL='.$manager->url($name));
                }

                $entryArguments = $this->pestArguments($workingDirectory, $arguments, implode(',', $entryTests));
                if ($entry->groups() !== []) {
                    $entryArguments[] = '--group='.implode(',', $entry->groups());
                }

                try {
                    $exitCode = $this->process->run(array_merge([$this->pestBinary($workingDirectory)], $entryArguments), $workingDirectory, true);
                } finally {
                    if ($browser) {
                        $manager->stop($name);
                    }
                }

                if ($exitCode !== 0) {
                    return $exitCode;
                }
            }

            return $exitCode;
        }

        $this->assertBrowserRequirements($this->selectedTests($config->tests(), $tests));

        if ($tests !== null) {
            putenv("WP_PEST_TESTS={$tests}");
        }

        return $this->process->run(array_merge([$this->pestBinary($workingDirectory)], $pestArguments), $workingDirectory, true);
    }

    /**
     * @param  list<string>  $configuredTests
     * @return list<string>
     */
    private function selectedTests(array $configuredTests, ?string $requestedTests): array
    {
        if ($requestedTests === null || $requestedTests === '') {
            return $configuredTests;
        }

        return array_values(array_intersect($configuredTests, array_map('trim', explode(',', $requestedTests))));
    }

    /**
     * @param  list<string>  $tests
     */
    private function assertBrowserRequirements(array $tests): void
    {
        if (in_array('browser', $tests, true) && ! function_exists('socket_create_listen')) {
            throw new ValidationException([$this->socketsExtensionMessage()]);
        }
    }

    private function socketsExtensionMessage(): string
    {
        $disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $disabledNotice = in_array('socket_create_listen', $disabledFunctions, true)
            ? ' It is listed in disable_functions.'
            : '';
        $loadedIni = php_ini_loaded_file() ?: '(none)';
        $scannedIni = trim((string) php_ini_scanned_files()) ?: '(none)';
        $scannedIni = str_replace("\n", ', ', $scannedIni);

        return sprintf(
            'The PHP function socket_create_listen() required by Pest Browser is unavailable in %s (PHP %s).%s Check that php has the sockets extension enabled. Loaded INI: %s; scanned INI files: %s. Code-only tests do not require it.',
            PHP_BINARY,
            PHP_VERSION,
            $disabledNotice,
            $loadedIni,
            $scannedIni,
        );
    }

    private function matrixList(string $workingDirectory): int
    {
        $config = (new ConfigLoader)->load($workingDirectory);

        foreach ($config->matrixCombinations() as $entry) {
            $plugins = [];
            foreach ($entry->pluginVersions() as $slug => $version) {
                $plugins[] = "{$slug}:{$version}";
            }

            echo $entry->name().' | WP '.$entry->wordpress().' | '.implode(',', $entry->tests());
            echo $plugins === [] ? '' : ' | '.implode(',', $plugins);
            echo $entry->groups() === [] ? '' : ' | groups='.implode(',', $entry->groups());
            echo PHP_EOL;
        }

        return 0;
    }

    private function matrixEntry(Config $config, string $name): MatrixCombination
    {
        foreach ($config->matrixCombinations() as $entry) {
            if ($entry->name() === $name) {
                return $entry;
            }
        }

        throw new ValidationException(["Unknown matrix combination: {$name}."]);
    }

    private function sandbox(string $workingDirectory, string $action, ?string $selection): int
    {
        $config = (new ConfigLoader)->load($workingDirectory);
        $manager = new SandboxManager($config, $workingDirectory);
        $exitCode = 0;
        $environment = $this->captureEnvironment([
            'WP_PEST_MATRIX',
            'WP_PEST_WORDPRESS_PATH',
            'WP_PEST_RESOLVED_WORDPRESS_VERSION',
        ]);

        try {
            foreach ($manager->matrixNames($selection) as $name) {
                putenv("WP_PEST_MATRIX={$name}");

                if (in_array($action, ['start', 'reset'], true)) {
                    $fixtureManager = new PluginFixture($config, $workingDirectory);
                    $fixture = $fixtureManager->ensure();
                    putenv("WP_PEST_WORDPRESS_PATH={$fixture}/wordpress");
                    $resolved = $fixtureManager->resolvedWordpressVersion();
                    putenv($resolved === null ? 'WP_PEST_RESOLVED_WORDPRESS_VERSION' : "WP_PEST_RESOLVED_WORDPRESS_VERSION={$resolved}");
                }

                $exitCode = $manager->{$action}($name);

                if ($exitCode !== 0) {
                    return $exitCode;
                }
            }
        } finally {
            $this->restoreEnvironment($environment);
        }

        return $exitCode;
    }

    private function help(): int
    {
        echo "Usage: wp-pest <command>\n";
        echo "Commands: init [--target=plugin|site], doctor, run, matrix:list, sandbox:start, sandbox:stop, sandbox:reset, sandbox:destroy\n";

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

            if (! $updated && $contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
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
        foreach ($arguments as $index => $argument) {
            if (str_starts_with($argument, "--{$name}=")) {
                return substr($argument, strlen($name) + 3);
            }
            if ($argument === "--{$name}" && isset($arguments[$index + 1])) {
                return $arguments[$index + 1];
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
        $filteredArguments = [];
        $skipNext = false;
        foreach ($arguments as $argument) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if (in_array($argument, ['--target', '--tests', '--matrix'], true)
                || ($tests !== null && $argument === '--testsuite')) {
                $skipNext = true;

                continue;
            }

            if (str_starts_with($argument, '--target=')
                || str_starts_with($argument, '--tests=')
                || str_starts_with($argument, '--matrix=')
                || ($tests !== null && str_starts_with($argument, '--testsuite='))) {
                continue;
            }

            $filteredArguments[] = $argument;
        }
        $arguments = $filteredArguments;

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
