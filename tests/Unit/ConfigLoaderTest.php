<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;

it('loads defaults for a site code target', function (): void {
    $config = (new ConfigLoader())->load($this->workspace);

    expect($config->target())->toBe('site')
        ->and($config->tests())->toBe(['code'])
        ->and($config->hasCodeTests())->toBeTrue()
        ->and($config->hasBrowserTests())->toBeFalse();
});

it('loads plugin target with code and browser tests', function (): void {
    file_put_contents($this->workspace . '/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],
    'plugin' => ['path' => 'my-plugin'],
    'sandbox' => [
        'matrix' => [
            'php83' => ['php' => '8.3', 'wordpress' => '^6.0'],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader())->load($this->workspace);

    expect($config->target())->toBe('plugin')
        ->and($config->tests())->toBe(['code', 'browser'])
        ->and($config->pluginPath())->toBe('my-plugin')
        ->and(array_keys($config->sandboxMatrix()))->toBe(['php83']);
});

it('requires a url for site browser tests', function (): void {
    file_put_contents($this->workspace . '/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['browser'],
];
PHP);

    (new ConfigLoader())->load($this->workspace);
})->throws(ValidationException::class, 'site.url is required');

it('requires a plugin path for plugin tests', function (): void {
    file_put_contents($this->workspace . '/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
];
PHP);

    (new ConfigLoader())->load($this->workspace);
})->throws(ValidationException::class, 'plugin.path is required');

it('applies environment overrides', function (): void {
    putenv('WP_PEST_TARGET=plugin');
    putenv('WP_PEST_TESTS=browser');
    putenv('WP_PEST_PLUGIN_PATH=plugin-from-env');

    try {
        $config = (new ConfigLoader())->load($this->workspace);

        expect($config->target())->toBe('plugin')
            ->and($config->tests())->toBe(['browser'])
            ->and($config->pluginPath())->toBe('plugin-from-env');
    } finally {
        putenv('WP_PEST_TARGET');
        putenv('WP_PEST_TESTS');
        putenv('WP_PEST_PLUGIN_PATH');
    }
});
