<?php

use CrossPeak\WpPest\WordPressTestCase;

// Separate process: these small WordPress doubles must not leak into other tests.
require $argv[1];

function wp_set_current_user(int $id): void
{
    $GLOBALS['current_user'] = (object) ['ID' => $id];
}

function wp_cache_flush(): void
{
    $GLOBALS['cache_flushes'] = ($GLOBALS['cache_flushes'] ?? 0) + 1;
}

final class LifecycleProbe extends WordPressTestCase
{
    public function start(): void
    {
        parent::setUp();
    }

    public function finish(): void
    {
        parent::tearDown();
    }
}

$pdo = $GLOBALS['@pdo'] = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE probe (value TEXT)');
$_POST = ['original' => 'value'];
wp_set_current_user(42);
$test = new LifecycleProbe('probe');
$test->start();
$pdo->exec("INSERT INTO probe VALUES ('temporary')");
$_POST = ['changed' => true];
wp_set_current_user(99);
$order = [];
$test->deferCleanup(static function () use (&$order): void {
    $order[] = 'first';
});
$test->deferCleanup(static function () use (&$order): void {
    $order[] = 'second';
    throw new RuntimeException('cleanup failed');
});
try {
    $test->finish();
} catch (RuntimeException $error) {
    $message = $error->getMessage();
}
echo json_encode([
    'rows' => $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn(),
    'post' => $_POST,
    'user' => $GLOBALS['current_user']->ID,
    'order' => $order,
    'error' => $message ?? null,
    'cache_flushed' => ($GLOBALS['cache_flushes'] ?? 0) > 0,
], JSON_THROW_ON_ERROR);
