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
 * @method self click(string $selector, array<string, mixed> $options = [])
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

    /** Return the current page URL. */
    public function url(): string
    {
        return $this->page->url();
    }

    /**
     * Click an element, using Pest Browser's default behavior unless options
     * are explicitly provided.
     *
     * @param  array<string, mixed>  $options
     */
    public function click(string $selector, array $options = []): self
    {
        if (method_exists($this->page, 'page')) {
            $page = $this->page->page();

            if (is_object($page) && method_exists($page, 'locator')) {
                $page->locator($selector)->click($options);

                return $this;
            }
        }

        // Keep support for simple wrappers and test doubles that don't expose
        // Pest Browser's underlying Playwright page.
        if ($options !== []) {
            throw new \InvalidArgumentException('Click options require access to Pest Browser\'s underlying Playwright page.');
        }

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
