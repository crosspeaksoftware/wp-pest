<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Sandbox;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Support\Process;

final class SandboxManager
{
    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
        private readonly Process $process = new Process(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function matrixNames(?string $selection = null): array
    {
        $names = array_keys($this->config->sandboxMatrix());

        if ($selection === null || $selection === '') {
            return $names === [] ? [] : [$names[0]];
        }

        if ($selection === 'all') {
            return $names;
        }

        if (! in_array($selection, $names, true)) {
            throw new ValidationException(["Unknown sandbox matrix entry: {$selection}."]);
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

        $path = $directory . DIRECTORY_SEPARATOR . 'compose.yml';
        file_put_contents($path, $this->composeYaml($entry));

        return $path;
    }

    public function start(string $name): int
    {
        $this->writeCompose($name);

        $up = $this->compose($name, ['up', '-d']);

        return $up === 0 ? $this->setupWordPress($name) : $up;
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

        return "http://127.0.0.1:{$port}";
    }

    private function entry(string $name): MatrixEntry
    {
        $matrix = $this->config->sandboxMatrix();

        if (! isset($matrix[$name]) || ! is_array($matrix[$name])) {
            throw new ValidationException(["Unknown sandbox matrix entry: {$name}."]);
        }

        return new MatrixEntry($name, $matrix[$name]);
    }

    /**
     * @param list<string> $arguments
     */
    private function compose(string $name, array $arguments): int
    {
        $compose = $this->sandboxDirectory($name) . DIRECTORY_SEPARATOR . 'compose.yml';
        $project = 'wp-pest-' . preg_replace('/[^a-z0-9_-]+/i', '-', basename($this->workingDirectory) . '-' . $name);

        return $this->process->run(array_merge(['docker', 'compose', '-p', $project, '-f', $compose], $arguments), $this->workingDirectory, true);
    }

    private function sandboxDirectory(string $name): string
    {
        $base = (string) $this->config->get('sandbox.path', '.wp-pest/sandboxes');

        return $this->workingDirectory . DIRECTORY_SEPARATOR . trim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    private function composeYaml(MatrixEntry $entry): string
    {
        $port = $entry->port();
        if ($port <= 0) {
            $port = 8080 + abs(crc32($entry->name)) % 1000;
        }

        $pluginPath = $this->config->pluginPath() ?? '.';
        $pluginMount = $this->absolutePath($pluginPath);
        $dependencyMounts = '';

        foreach (array_merge($this->config->dependencyPlugins(), $entry->plugins()) as $index => $dependency) {
            if (is_dir($this->absolutePath($dependency))) {
                $dependencyMounts .= "      - {$this->absolutePath($dependency)}:/var/www/html/wp-content/plugins/dependency-{$index}\n";
            }
        }

        $environment = '';
        foreach ($entry->env() as $key => $value) {
            $environment .= "      {$key}: " . json_encode($value, JSON_THROW_ON_ERROR) . "\n";
        }

        return <<<YAML
services:
  wordpress:
    image: wordpress:php{$entry->php()}-apache
    ports:
      - "{$port}:80"
    environment:
      WORDPRESS_DB_HOST: database
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
{$environment}    volumes:
      - wordpress:/var/www/html
      - {$pluginMount}:/var/www/html/wp-content/plugins/plugin-under-test
{$dependencyMounts}    depends_on:
      - database

  wpcli:
    image: wordpress:cli-php{$entry->php()}
    environment:
      WORDPRESS_DB_HOST: database
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
    volumes:
      - wordpress:/var/www/html
      - {$pluginMount}:/var/www/html/wp-content/plugins/plugin-under-test
{$dependencyMounts}    depends_on:
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

        return $this->workingDirectory . DIRECTORY_SEPARATOR . $path;
    }

    private function setupWordPress(string $name): int
    {
        $url = $this->url($name);
        $script = implode(' && ', [
            'until wp db check --allow-root >/dev/null 2>&1; do sleep 2; done',
            'wp core install --allow-root --url=' . escapeshellarg($url) . ' --title=' . escapeshellarg('WP Pest') . ' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email || true',
            'wp plugin activate plugin-under-test --allow-root',
        ]);

        return $this->compose($name, ['run', '--rm', 'wpcli', 'sh', '-lc', $script]);
    }
}
