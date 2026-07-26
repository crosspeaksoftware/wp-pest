<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Runtime\WpPest;

it('prefers the active sandbox url over the configured site url', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['browser'],
    'site' => ['url' => 'http://localhost'],
];
PHP);

    putenv('WP_PEST_ACTIVE_URL=http://127.0.0.1:8765');

    try {
        $runtime = new WpPest((new ConfigLoader)->load($this->workspace), $this->workspace);

        expect($runtime->url())->toBe('http://127.0.0.1:8765');
    } finally {
        putenv('WP_PEST_ACTIVE_URL');
    }
});

it('derives a plugin browser url from the active matrix without an environment override', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'profiles' => ['default' => ['tests' => ['browser']]],
        'browser' => ['port' => 8765],
    ],
];
PHP);

    putenv('WP_PEST_MATRIX=default--wp-7.0');

    try {
        $runtime = new WpPest((new ConfigLoader)->load($this->workspace), $this->workspace);

        expect($runtime->url())->toBe('http://127.0.0.1:8765');
    } finally {
        putenv('WP_PEST_MATRIX');
    }
});

it('does not bootstrap wordpress for a browser-only command', function (): void {
    $arguments = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['pest', '--testsuite=Browser'];

    try {
        $runtime = new WpPest((new ConfigLoader)->load($this->workspace), $this->workspace);

        expect($runtime->shouldBootstrapWordPress())->toBeFalse();
    } finally {
        $_SERVER['argv'] = $arguments;
    }
});

it('bootstraps wordpress before discovering code tests', function (): void {
    $arguments = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['pest', '--testsuite', 'Code'];

    try {
        $runtime = new WpPest((new ConfigLoader)->load($this->workspace), $this->workspace);

        expect($runtime->shouldBootstrapWordPress())->toBeTrue();
    } finally {
        $_SERVER['argv'] = $arguments;
    }
});
