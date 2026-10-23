<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use CrossPeak\WpPest\Sandbox\SandboxManager;
use CrossPeak\WpPest\Support\SiteDetector;
use PDO;
use RuntimeException;

final class WpPest
{
    private static ?self $instance = null;

    private ?string $managedBrowserUrl = null;

    private ?Factory $factory = null;

    private bool $databaseTransactionActive = false;

    private bool $sqliteTransaction = false;

    public function __construct(
        private readonly Config $config,
        private readonly string $workingDirectory,
    ) {}

    public static function fromCurrentWorkingDirectory(): self
    {
        return self::$instance ??= new self(
            (new ConfigLoader)->load(getcwd() ?: '.'),
            getcwd() ?: '.',
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function target(): string
    {
        return $this->config->target();
    }

    public function hasCodeTests(): bool
    {
        return $this->config->hasCodeTests();
    }

    public function hasBrowserTests(): bool
    {
        return $this->config->hasBrowserTests();
    }

    public function factory(): Factory
    {
        return $this->factory ??= new Factory;
    }

    public function actingAsAdministrator(): int
    {
        if (! function_exists('wp_set_current_user') || ! function_exists('get_user_by')) {
            throw new RuntimeException('WordPress must be bootstrapped before authenticating users.');
        }

        $administrator = get_user_by('login', 'admin');
        $administratorId = is_object($administrator) ? (get_object_vars($administrator)['ID'] ?? null) : null;
        $userId = is_int($administratorId)
            ? $administratorId
            : $this->factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($userId);

        return $userId;
    }

    public function shouldBootstrapWordPress(): bool
    {
        $arguments = array_values(array_map('strval', $_SERVER['argv'] ?? []));
        $suite = $this->commandLineOption($arguments, 'testsuite');
        if ($suite !== null) {
            return in_array('code', array_map('strtolower', explode(',', $suite)), true);
        }

        $hasCodePath = false;
        $hasBrowserPath = false;
        foreach ($arguments as $argument) {
            $path = str_replace('\\', '/', strtolower($argument));
            $hasCodePath = $hasCodePath || str_contains($path, 'tests/code');
            $hasBrowserPath = $hasBrowserPath || str_contains($path, 'tests/browser');
        }
        if ($hasCodePath || $hasBrowserPath) {
            return $hasCodePath;
        }

        $tests = getenv('WP_PEST_TESTS');
        if (is_string($tests) && $tests !== '') {
            return in_array('code', array_map('strtolower', array_map('trim', explode(',', $tests))), true);
        }

        return $this->hasCodeTests();
    }

    public function url(): ?string
    {
        $activeUrl = getenv('WP_PEST_ACTIVE_URL');

        if (is_string($activeUrl) && $activeUrl !== '') {
            return $activeUrl;
        }

        $matrix = $this->config->activeMatrix();
        if ($this->target() === 'plugin' && $matrix !== null) {
            $selectedMatrix = getenv('WP_PEST_MATRIX');
            if (! is_string($selectedMatrix) || $selectedMatrix === '') {
                return $this->startManagedBrowserSandbox($matrix->name());
            }

            return (new SandboxManager($this->config, $this->workingDirectory))->url($matrix->name());
        }

        return $this->config->siteUrl();
    }

    /**
     * Resolve a WordPress-relative path against the active site's URL.
     */
    public function urlFor(string $path = '/'): string
    {
        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        $url = $this->url();
        if ($url === null || $url === '') {
            throw new RuntimeException('A WordPress browser URL is required to resolve a relative path.');
        }

        return rtrim($url, '/').'/'.ltrim($path, '/');
    }

    private function startManagedBrowserSandbox(string $name): string
    {
        if ($this->managedBrowserUrl !== null) {
            return $this->managedBrowserUrl;
        }

        putenv("WP_PEST_MATRIX={$name}");

        $fixtureManager = new PluginFixture($this->config, $this->workingDirectory);
        $fixture = $fixtureManager->ensure();
        putenv("WP_PEST_WORDPRESS_PATH={$fixture}/wordpress");
        $resolved = $fixtureManager->resolvedWordpressVersion();
        putenv($resolved === null ? 'WP_PEST_RESOLVED_WORDPRESS_VERSION' : "WP_PEST_RESOLVED_WORDPRESS_VERSION={$resolved}");

        $manager = new SandboxManager($this->config, $this->workingDirectory);
        if ($manager->start($name, fresh: true) !== 0) {
            throw new RuntimeException("Unable to start browser sandbox {$name}.");
        }

        $this->managedBrowserUrl = $manager->url($name);
        putenv("WP_PEST_ACTIVE_URL={$this->managedBrowserUrl}");

        register_shutdown_function(static function () use ($manager, $name): void {
            $manager->stop($name);
        });

        return $this->managedBrowserUrl;
    }

    public function wordpressPath(): ?string
    {
        if ($this->target() === 'site') {
            return $this->config->sitePath()
                ? $this->absolutePath($this->config->sitePath())
                : (new SiteDetector)->detect($this->workingDirectory);
        }

        $fixture = getenv('WP_PEST_WORDPRESS_PATH');

        return is_string($fixture) && $fixture !== '' ? $fixture : null;
    }

    public function pluginPath(): ?string
    {
        $path = $this->config->pluginPath();

        return $path === null ? null : $this->absolutePath($path);
    }

    public function matrixName(): ?string
    {
        $name = getenv('WP_PEST_MATRIX');

        return is_string($name) && $name !== '' ? $name : $this->config->activeMatrix()?->name();
    }

    public function sandboxDriver(): string
    {
        $name = $this->matrixName();
        if ($name === null) {
            throw new RuntimeException('No active matrix sandbox is available.');
        }

        return (new SandboxManager($this->config, $this->workingDirectory))->driver($name);
    }

    /**
     * Run WP-CLI inside the active plugin browser sandbox.
     *
     * @param  list<string>  $arguments
     */
    public function wpCli(array $arguments): string
    {
        if ($this->target() !== 'plugin') {
            throw new RuntimeException('WP-CLI sandbox commands are only available for plugin targets.');
        }

        $this->url();
        $name = $this->matrixName();
        if ($name === null) {
            throw new RuntimeException('No active matrix sandbox is available.');
        }

        return (new SandboxManager($this->config, $this->workingDirectory))->wpCli($name, $arguments);
    }

    /** @return array<string, mixed>|null */
    public function matrix(): ?array
    {
        return $this->config->activeMatrix()?->all();
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function phpVersionIs(string $constraint): bool
    {
        return $this->versionIs(PHP_VERSION, $constraint);
    }

    public function wordpressVersion(): ?string
    {
        $getBlogInfo = 'get_bloginfo';

        return function_exists($getBlogInfo) ? (string) $this->callFunction($getBlogInfo, 'version') : null;
    }

    public function wordpressVersionIs(string $constraint): bool
    {
        $version = $this->wordpressVersion();

        return $version !== null && $this->versionIs($version, $constraint);
    }

    public function hasPlugin(string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);
        foreach ($this->config->dependencyPlugins() as $plugin) {
            if ($plugin->slug() === $slug) {
                return true;
            }
        }

        return false;
    }

    public function pluginVersion(string $slug): ?string
    {
        $slug = $this->normalizeSlug($slug);
        foreach ($this->config->dependencyPlugins() as $plugin) {
            if ($plugin->slug() === $slug) {
                return $plugin->version() === '*' ? null : $plugin->version();
            }
        }

        return null;
    }

    public function pluginVersionIs(string $slug, string $constraint): bool
    {
        $version = $this->pluginVersion($slug);

        return $version !== null && $this->versionIs($version, $constraint);
    }

    public function bootstrapWordPress(): bool
    {
        $path = $this->wordpressPath();

        if ($path === null && $this->target() === 'plugin') {
            $fixture = (new PluginFixture($this->config, $this->workingDirectory))->ensure();
            $path = $fixture.DIRECTORY_SEPARATOR.'wordpress';
        }

        if ($path === null) {
            return false;
        }

        $wpLoad = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'wp-load.php';

        if (! is_file($wpLoad)) {
            return false;
        }

        require_once $wpLoad;

        $this->configureSqlite();
        $this->applyWordpressOptions();

        return true;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->applyWordpressOptions();

        if (! $this->config->useDatabaseTransactions() || $this->databaseTransactionActive) {
            return;
        }

        // SQLite's WordPress adapter tracks transaction nesting itself. Going
        // directly through PDO makes its next query try to start a second one.
        $database = $GLOBALS['wpdb'] ?? null;
        if (is_object($database) && method_exists($database, 'query')) {
            if ($database->query('START TRANSACTION') === false) {
                throw new RuntimeException('Unable to start the WordPress test transaction.');
            }
            $this->databaseTransactionActive = true;

            return;
        }

        $pdo = $GLOBALS['@pdo'] ?? null;
        if ($pdo instanceof PDO) {
            if ($pdo->inTransaction()) {
                throw new RuntimeException('A database transaction is already active before the WordPress test.');
            }

            $pdo->beginTransaction();
            $this->sqliteTransaction = true;
            $this->databaseTransactionActive = true;

            return;
        }
    }

    public function rollbackDatabaseTransaction(): void
    {
        if (! $this->databaseTransactionActive) {
            return;
        }

        try {
            $pdo = $GLOBALS['@pdo'] ?? null;
            if ($this->sqliteTransaction && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            } else {
                $database = $GLOBALS['wpdb'] ?? null;
                if (is_object($database) && method_exists($database, 'query') && $database->query('ROLLBACK') === false) {
                    throw new RuntimeException('Unable to roll back the WordPress test transaction.');
                }
            }
        } finally {
            $this->databaseTransactionActive = false;
            $this->sqliteTransaction = false;
        }

        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    private function configureSqlite(): void
    {
        $pdo = $GLOBALS['@pdo'] ?? null;
        if (! $pdo instanceof PDO) {
            return;
        }

        $pdo->exec('PRAGMA journal_mode = '.$this->config->sqliteJournalMode());
        $pdo->exec('PRAGMA synchronous = '.$this->config->sqliteSynchronous());
    }

    private function applyWordpressOptions(): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        $filters = $GLOBALS['wp_pest_option_filters'] ?? [];

        foreach ($filters as $option => $filter) {
            if (function_exists('remove_filter') && is_string($option) && is_callable($filter)) {
                remove_filter('pre_option_'.$option, $filter);
            }
        }

        foreach ($this->config->wordpressOptions() as $option => $value) {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option, 'options');
            }

            update_option($option, $value);
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    private function callFunction(string $name, mixed ...$arguments): mixed
    {
        return (new \ReflectionFunction($name))->invokeArgs($arguments);
    }

    private function versionIs(string $version, string $constraint): bool
    {
        if (preg_match('/^(>=|<=|!=|==|>|<|=)?\s*(.+)$/', trim($constraint), $matches) !== 1) {
            return false;
        }

        $operator = $matches[1] === '' ? '==' : $matches[1];
        $operator = $operator === '=' ? '==' : $operator;

        return version_compare($version, $matches[2], $operator);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = str_starts_with($slug, 'wpackagist-plugin/') ? substr($slug, strlen('wpackagist-plugin/')) : $slug;

        return trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($slug)), '-');
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }

    /** @param list<string> $arguments */
    private function commandLineOption(array $arguments, string $name): ?string
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
}
