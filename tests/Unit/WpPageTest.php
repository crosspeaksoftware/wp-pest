<?php

declare(strict_types=1);

use CrossPeak\WpPest\Browser\WpPage;

it('resolves relative navigations and keeps fluent browser chains wrapped', function (): void {
    $browserPage = new RecordingBrowserPage;
    $page = new WpPage($browserPage, static fn (string $path): string => 'http://wordpress.test/'.ltrim($path, '/'));

    $result = $page->click('#open')->navigate('/wp-admin/plugins.php');

    expect($result)->toBe($page)
        ->and($browserPage->calls)->toBe([
            ['click', '#open'],
            ['navigate', 'http://wordpress.test/wp-admin/plugins.php', []],
        ]);
});

final class RecordingBrowserPage
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function click(string $selector): self
    {
        $this->calls[] = ['click', $selector];

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function navigate(string $url, array $options = []): self
    {
        $this->calls[] = ['navigate', $url, $options];

        return $this;
    }
}
