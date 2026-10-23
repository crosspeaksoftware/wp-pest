<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use Closure;
use CrossPeak\WpPest\Runtime\WordPressState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Code-test lifecycle; browser requests require their own sandbox isolation. */
abstract class WordPressTestCase extends TestCase
{
    private ?WordPressState $wordpressState = null;

    /** @var list<Closure(): void> */
    private array $cleanupCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('wp_set_current_user')) {
            throw new RuntimeException('Bootstrap WordPress in tests/Pest.php before using WordPressTestCase.');
        }

        $this->wordpressState = new WordPressState;
        wp_pest()->beginDatabaseTransaction();
    }

    /** Register cleanup immediately after creating a file or changing plugin state. */
    public function deferCleanup(Closure $callback): void
    {
        $this->cleanupCallbacks[] = $callback;
    }

    protected function tearDown(): void
    {
        $failure = null;
        try {
            while ($callback = array_pop($this->cleanupCallbacks)) {
                try {
                    $callback();
                } catch (Throwable $error) {
                    $failure ??= $error;
                }
            }
            try {
                wp_pest()->rollbackDatabaseTransaction();
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        } finally {
            $this->wordpressState?->restore();
            $this->wordpressState = null;
            // Cache isolation also applies when database transactions are disabled.
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            parent::tearDown();
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
