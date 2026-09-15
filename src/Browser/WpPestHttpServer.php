<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Browser;

use Closure;
use Pest\Browser\Contracts\HttpServer;
use Throwable;

/**
 * Connects Pest Browser URL handling to the active wp-pest WordPress site.
 *
 * wp-pest owns the WordPress server lifecycle, so this adapter only resolves
 * paths and asks wp-pest to initialize its sandbox before browser tests run.
 */
final class WpPestHttpServer implements HttpServer
{
    /** @var Closure(string): string */
    private readonly Closure $resolveUrl;

    /**
     * @param  Closure(string): string|null  $resolveUrl  Optional URL resolver, primarily useful for isolated tests.
     */
    public function __construct(?Closure $resolveUrl = null)
    {
        $this->resolveUrl = $resolveUrl ?? static fn (string $path): string => \wp_pest()->urlFor($path);
    }

    public function start(): void
    {
        // The wp-pest sandbox is started by url() during bootstrap().
    }

    public function stop(): void
    {
        // wp-pest registers its own sandbox shutdown handler.
    }

    public function rewrite(string $url): string
    {
        return ($this->resolveUrl)($url);
    }

    public function flush(): void
    {
        // The WordPress sandbox is shared for the duration of the test run.
    }

    public function bootstrap(): void
    {
        ($this->resolveUrl)('/');
    }

    public function lastThrowable(): ?Throwable
    {
        return null;
    }

    public function throwLastThrowableIfNeeded(): void
    {
        // Requests are handled by the independently managed WordPress server.
    }
}
