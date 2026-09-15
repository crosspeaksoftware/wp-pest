<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Browser;

use Closure;

/**
 * Adds WordPress-site-relative navigation to a Pest Browser page.
 *
 * The wrapped Pest page stays optional: this class only requires Pest Browser
 * when a browser helper is actually used.
 *
 * @method self assertNoJavaScriptErrors()
 * @method self assertPathBeginsWith(string $path)
 * @method self assertSee(string $text)
 * @method self assertSourceHas(string $source)
 * @method self click(string $selector)
 * @method self fill(string $selector, string $value)
 */
final class WpPage
{
    /**
     * @param  Closure(string): string  $resolveUrl
     */
    public function __construct(
        private object $page,
        private readonly Closure $resolveUrl,
    ) {}

    /**
     * Navigate within the active WordPress site, or to an absolute URL.
     *
     * @param  array<string, mixed>  $options
     */
    public function navigate(string $url, array $options = []): self
    {
        $result = $this->page->navigate(($this->resolveUrl)($url), $options);
        $this->page = $result;

        return $this;
    }

    /**
     * Click once without Pest Browser's assertion retry loop.
     *
     * A click can submit a form and navigate away. Retrying that action while
     * the navigation is in progress can repeatedly submit it (and, with Pest
     * Browser 5, wait until the full browser timeout even after the new page
     * has loaded).
     */
    public function click(string $selector): self
    {
        if (method_exists($this->page, 'page')) {
            $page = $this->page->page();

            if (is_object($page) && method_exists($page, 'locator')) {
                $page->locator($selector)->click(['noWaitAfter' => true]);

                return $this;
            }
        }

        // Keep support for simple wrappers and test doubles that don't expose
        // Pest Browser's underlying Playwright page.
        $this->page->click($selector);

        return $this;
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->page->{$name}(...$arguments);
        if ($this->isPageResult($result)) {
            $this->page = $result;

            return $this;
        }

        return $result;
    }

    private function isPageResult(mixed $result): bool
    {
        if ($result === $this->page) {
            return true;
        }

        return is_object($result) && str_starts_with($result::class, 'Pest\\Browser\\Api\\');
    }
}
