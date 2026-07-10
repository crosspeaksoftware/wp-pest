<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Cli;

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use CrossPeak\WpPest\Sandbox\SandboxManager;
use CrossPeak\WpPest\Support\Process;
use CrossPeak\WpPest\Support\SiteDetector;

final class Application
{
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $workingDirectory = getcwd() ?: '.';

        try {
            return match ($command) {
                'init' => $this->init($workingDirectory),
                'doctor' => $this->doctor($workingDirectory),
                'run' => $this->runPest($workingDirectory, array_slice($argv, 2)),
                'sandbox:start' => $this->sandbox($workingDirectory, 'start', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:stop' => $this->sandbox($workingDirectory, 'stop', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:reset' => $this->sandbox($workingDirectory, 'reset', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                'sandbox:destroy' => $this->sandbox($workingDirectory, 'destroy', $argv[2] ?? getenv('WP_PEST_MATRIX') ?: null),
                default => $this->help(),
            };
        } catch (ValidationException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    private function init(string $workingDirectory): int
    {
        $config = $workingDirectory . DIRECTORY_SEPARATOR . 'wp-pest.php';
        $pest = $workingDirectory . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Pest.php';
        $gitignore = $workingDirectory . DIRECTORY_SEPARATOR . '.gitignore';

        if (! is_file($config)) {
            file_put_contents($config, file_get_contents(__DIR__ . '/../../examples/wp-pest.php'));
            echo "Created wp-pest.php\n";
        }

        if (! is_dir(dirname($pest))) {
            mkdir(dirname($pest), 0775, true);
        }

        if (! is_file($pest)) {
            file_put_contents($pest, "<?php\n\ndeclare(strict_types=1);\n\nuses()->beforeEach(function (): void {\n    // Project-specific Pest setup can go here.\n});\n");
            echo "Created tests/Pest.php\n";
        }

        $ignore = ".wp-pest/\n";
        if (! is_file($gitignore) || ! str_contains((string) file_get_contents($gitignore), $ignore)) {
            file_put_contents($gitignore, $ignore, FILE_APPEND);
            echo "Updated .gitignore\n";
        }

        return 0;
    }

    private function doctor(string $workingDirectory): int
    {
        $config = (new ConfigLoader())->load($workingDirectory);
        $errors = [];

        if ($config->target() === 'site' && $config->hasCodeTests() && (new SiteDetector())->detect($config->sitePath() ?? $workingDirectory) === null) {
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
     * @param list<string> $arguments
     */
    private function runPest(string $workingDirectory, array $arguments): int
    {
        $target = $this->option($arguments, 'target');
        $tests = $this->option($arguments, 'tests');

        if ($target !== null) {
            putenv("WP_PEST_TARGET={$target}");
        }

        if ($tests !== null) {
            putenv("WP_PEST_TESTS={$tests}");
        }

        $config = (new ConfigLoader())->load($workingDirectory);

        if ($config->target() === 'plugin' && $config->hasCodeTests()) {
            $fixture = (new PluginFixture($config, $workingDirectory))->ensure();
            putenv("WP_PEST_WORDPRESS_PATH={$fixture}/wordpress");
        }

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
                putenv('WP_PEST_ACTIVE_URL=' . $manager->url($name));
                $exitCode = (new Process())->run([$this->pestBinary($workingDirectory)], $workingDirectory, true);
                $manager->stop($name);

                if ($exitCode !== 0) {
                    return $exitCode;
                }
            }

            return $exitCode;
        }

        return (new Process())->run(array_merge([$this->pestBinary($workingDirectory)], $arguments), $workingDirectory, true);
    }

    private function sandbox(string $workingDirectory, string $action, ?string $selection): int
    {
        $config = (new ConfigLoader())->load($workingDirectory);
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
        echo "Commands: init, doctor, run, sandbox:start, sandbox:stop, sandbox:reset, sandbox:destroy\n";

        return 0;
    }

    private function commandExists(string $command): bool
    {
        exec('command -v ' . escapeshellarg($command), $output, $exitCode);

        return $exitCode === 0;
    }

    private function pestBinary(string $workingDirectory): string
    {
        return $workingDirectory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pest';
    }

    private function absolutePath(string $workingDirectory, string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $workingDirectory . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param list<string> $arguments
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
}
