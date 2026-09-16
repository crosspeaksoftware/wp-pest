<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Matrix\MatrixCombination;
use CrossPeak\WpPest\Support\Process;
use CrossPeak\WpPest\Support\ProcessRunner;

final class SandboxManager
{
    /** @var list<string>|null */
    private ?array $composeCommand = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly ProcessRunner $process = new Process,
    ) {}

    /**
     * @return list<string>
     */
    public function matrixNames(?string $selection = null): array
    {
        $names = array_map(static fn (MatrixCombination $entry): string => $entry->name(), $this->config->matrixCombinations());

        if ($selection === null || $selection === '') {
            return $names === [] ? [] : [$names[0]];
        }

        if ($selection === 'all') {
            return $names;
        }

        if (! in_array($selection, $names, true)) {
            throw new ValidationException(["Unknown matrix combination: {$selection}."]);
        }

        return [$selection];
    }

    public function writeCompose(string $name): string
    {
        $entry = $this->entry($name);
        $directory = $this->sandboxDirectory($name);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'compose.yml';
        file_put_contents($path, $this->composeYaml($entry));

        return $path;
    }

    public function start(string $name, bool $fresh = false): int
    {
        $this->writeCompose($name);
        $seededFromCache = false;

        if ($fresh) {
            $down = $this->compose($name, ['down', '--volumes', '--remove-orphans']);
            if ($down !== 0) {
                return $down;
            }

            $seed = $this->seedWordpressVolume($name);
            if ($seed !== null && $seed !== 0) {
                return $seed;
            }
            $seededFromCache = $seed === 0;
        }

        $up = $this->compose($name, ['up', '-d']);

        return $up === 0 ? $this->setupWordPress($name, $seededFromCache) : $up;
    }

    public function stop(string $name): int
    {
        return $this->compose($name, ['stop']);
    }

    public function reset(string $name): int
    {
        $down = $this->compose($name, ['down', '--volumes', '--remove-orphans']);

        return $down === 0 ? $this->start($name) : $down;
    }

    public function destroy(string $name): int
    {
        return $this->compose($name, ['down', '--volumes', '--remove-orphans']);
    }

    public function url(string $name): string
    {
        $entry = $this->entry($name);
        $port = $entry->port();

        if ($port <= 0) {
            $port = 8080 + abs(crc32($name)) % 1000;
        }

        return "http://{$this->browserHost()}:{$port}";
    }

    /**
     * Run WP-CLI inside the active sandbox and return its standard output.
     *
     * @param  list<string>  $arguments
     */
    public function wpCli(string $name, array $arguments): string
    {
        $command = array_merge(
            $this->composeCommand($name),
            ['run', '--rm', '--no-deps', 'wpcli', 'php', '-d', 'memory_limit=512M', '/usr/local/bin/wp'],
            $arguments,
            ['--allow-root']
        );

        return $this->process->capture($command, $this->workingDirectory);
    }

    private function entry(string $name): MatrixCombination
    {
        foreach ($this->config->matrixCombinations() as $entry) {
            if ($entry->name() === $name) {
                return $entry;
            }
        }

        throw new ValidationException(["Unknown matrix combination: {$name}."]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function compose(string $name, array $arguments): int
    {
        return $this->process->run(array_merge($this->composeCommand($name), $arguments), $this->workingDirectory, true);
    }

    /** @return list<string> */
    private function composeCommand(string $name): array
    {
        $compose = $this->sandboxDirectory($name).DIRECTORY_SEPARATOR.'compose.yml';
        $project = 'wp-pest-'.preg_replace('/[^a-z0-9_-]+/i', '-', basename($this->workingDirectory).'-'.$name);

        $this->composeCommand ??= DockerCompose::command($this->process);

        return [...$this->composeCommand, '-p', $project, '-f', $compose];
    }

    private function sandboxDirectory(string $name): string
    {
        $base = (string) $this->config->get('sandbox.path', '.wp-pest/sandboxes');

        return $this->workingDirectory.DIRECTORY_SEPARATOR.trim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }

    private function composeYaml(MatrixCombination $entry): string
    {
        $port = $entry->port();
        if ($port <= 0) {
            $port = 8080 + abs(crc32($entry->name())) % 1000;
        }

        $pluginPath = $this->config->pluginPath() ?? '.';
        $pluginMount = $this->absolutePath($pluginPath);
        $dependencyMounts = '';
        $cacheMount = '';

        $wordpressCache = getenv('WP_PEST_WORDPRESS_PATH');
        if (is_string($wordpressCache) && is_dir($wordpressCache)) {
            $cacheMount = "      - {$wordpressCache}:/wp-pest-cache/wordpress:ro\n";
        }

        foreach ($entry->plugins() as $index => $dependency) {
            if ($dependency->isLocalPath() && is_dir($this->absolutePath($dependency->source()))) {
                $dependencyMounts .= "      - {$this->absolutePath($dependency->source())}:/var/www/html/wp-content/plugins/dependency-{$index}\n";

                continue;
            }

            $fixturePath = $this->fixtureDependencyPath($dependency->slug());
            if ($fixturePath !== null) {
                $dependencyMounts .= "      - {$fixturePath}:/var/www/html/wp-content/plugins/{$dependency->slug()}\n";
            }
        }

        $containerEnvironment = [
            'HTTP_HOST' => $this->browserHost().":{$port}",
            'WORDPRESS_DB_HOST' => 'database',
            'WORDPRESS_DB_NAME' => 'wordpress',
            'WORDPRESS_DB_USER' => 'wordpress',
            'WORDPRESS_DB_PASSWORD' => 'wordpress',
            'WORDPRESS_DEBUG' => '1',
            'WORDPRESS_CONFIG_EXTRA' => "define('WP_DEFAULT_THEME', 'twentytwentyfive');\ndefine('WP_DEBUG_LOG', '/proc/1/fd/2');\ndefine('WP_DEBUG_DISPLAY', false);",
        ];
        foreach ($entry->env() as $key => $value) {
            if ($key === 'WORDPRESS_CONFIG_EXTRA') {
                $containerEnvironment[$key] .= "\n{$value}";

                continue;
            }

            $containerEnvironment[$key] = $value;
        }

        $environment = '';
        foreach ($containerEnvironment as $key => $value) {
            $environment .= "      {$key}: ".json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        }

        $php = $entry->phpVersion();
        $wordpressImage = "wordpress:php{$php}-apache";

        return <<<YAML
services:
  wordpress:
    image: {$wordpressImage}
    ports:
      - "{$port}:80"
    environment:
{$environment}    volumes:
      - wordpress:/var/www/html
      - {$pluginMount}:/var/www/html/wp-content/plugins/plugin-under-test
{$dependencyMounts}    depends_on:
      - database

  wpcli:
    image: wordpress:cli-php{$php}
    user: "0:0"
    environment:
{$environment}    volumes:
      - wordpress:/var/www/html
      - {$pluginMount}:/var/www/html/wp-content/plugins/plugin-under-test
{$dependencyMounts}{$cacheMount}    depends_on:
      - database
      - wordpress

  database:
    image: {$entry->database()}
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
      MYSQL_ROOT_PASSWORD: wordpress
    volumes:
      - database:/var/lib/mysql

volumes:
  wordpress:
  database:

YAML;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }

    private function browserHost(): string
    {
        $host = getenv('WP_PEST_BROWSER_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private function setupWordPress(string $name, bool $seededFromCache = false): int
    {
        $url = $this->url($name);
        $wp = 'php -d memory_limit=512M /usr/local/bin/wp';
        $install = $wp.' core install --allow-root --url='.escapeshellarg($url).' --title='.escapeshellarg('WP Pest').' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email';
        $databaseProbe = <<<'PHP'
$socket = @fsockopen('database', 3306);
if ($socket === false) {
    exit(1);
}
fclose($socket);
PHP;
        $commands = [
            ...$this->wordpressInstallCommands($wp, $seededFromCache),
            'attempt=0; until php -r '.escapeshellarg($databaseProbe).'; do attempt=$((attempt + 1)); if [ "$attempt" -ge 60 ]; then echo "Database did not become ready." >&2; exit 1; fi; sleep 1; done',
            '{ if '.$wp.' core is-installed --allow-root; then true; else attempt=0; until '.$install.'; do attempt=$((attempt + 1)); if [ "$attempt" -ge 30 ]; then exit 1; fi; sleep 2; done; fi; }',
        ];

        foreach ($this->entry($name)->plugins() as $index => $dependency) {
            if ($dependency->isLocalPath() && is_dir($this->absolutePath($dependency->source()))) {
                $commands[] = $wp.' plugin activate '.escapeshellarg('dependency-'.$index).' --allow-root';

                continue;
            }

            if ($this->fixtureDependencyPath($dependency->slug()) !== null) {
                $commands[] = $wp.' plugin activate '.escapeshellarg($dependency->slug()).' --allow-root';

                continue;
            }

            $source = $dependency->isUrl() ? $dependency->source() : $dependency->slug();
            $version = ! $dependency->isUrl() && preg_match('/^\d+(?:\.\d+)*(?:[-+][a-z0-9.-]+)?$/i', $dependency->version()) === 1
                ? ' --version='.escapeshellarg($dependency->version())
                : '';
            $commands[] = $wp.' plugin install '.escapeshellarg($source).$version.' --activate --force --allow-root';
        }

        $commands[] = $wp.' plugin activate plugin-under-test --allow-root';
        $script = implode(' && ', $commands);

        return $this->compose($name, ['run', '--rm', 'wpcli', 'sh', '-lc', $script]);
    }

    /** @return list<string> */
    private function wordpressInstallCommands(string $wp, bool $seededFromCache): array
    {
        if ($seededFromCache) {
            return [];
        }

        $resolvedWordpress = getenv('WP_PEST_RESOLVED_WORDPRESS_VERSION');
        if (! is_string($resolvedWordpress) || $resolvedWordpress === '') {
            return [];
        }

        return [
            $wp.' core download --allow-root --force --version='.escapeshellarg($resolvedWordpress),
        ];
    }

    /**
     * Seed a fresh runtime volume from the exact WordPress fixture Composer already resolved.
     *
     * @return int|null Null when no usable cache is available.
     */
    private function seedWordpressVolume(string $name): ?int
    {
        $wordpressCache = getenv('WP_PEST_WORDPRESS_PATH');
        if (! is_string($wordpressCache) || ! is_file($wordpressCache.DIRECTORY_SEPARATOR.'wp-load.php')) {
            return null;
        }

        $script = 'mkdir -p /var/www/html/wp-content/themes'
            .' && cp -a /wp-pest-cache/wordpress/wp-admin /wp-pest-cache/wordpress/wp-includes /var/www/html/'
            .' && find /wp-pest-cache/wordpress -maxdepth 1 -type f ! -name wp-config.php -exec cp -a {} /var/www/html/ \\;'
            .' && cp -a /wp-pest-cache/wordpress/wp-content/themes/. /var/www/html/wp-content/themes/';

        return $this->compose($name, ['run', '--rm', '--no-deps', 'wpcli', 'sh', '-lc', $script]);
    }

    private function fixtureDependencyPath(string $slug): ?string
    {
        $wordpressPath = getenv('WP_PEST_WORDPRESS_PATH');
        if (! is_string($wordpressPath) || $wordpressPath === '') {
            return null;
        }

        $path = rtrim($wordpressPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'wp-content'
            .DIRECTORY_SEPARATOR.'plugins'
            .DIRECTORY_SEPARATOR.$slug;

        return is_dir($path) ? $path : null;
    }
}
