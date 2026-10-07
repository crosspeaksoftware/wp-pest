<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;

it('loads defaults for a site code target', function (): void {
    $config = (new ConfigLoader)->load($this->workspace);

    expect($config->target())->toBe('site')
        ->and($config->tests())->toBe(['code'])
        ->and($config->hasCodeTests())->toBeTrue()
        ->and($config->hasBrowserTests())->toBeFalse()
        ->and($config->activatePlugins())->toBeTrue()
        ->and($config->wordpressOptions())->toBe([])
        ->and($config->useDatabaseTransactions())->toBeTrue()
        ->and($config->sqliteJournalMode())->toBe('MEMORY')
        ->and($config->sqliteSynchronous())->toBe('OFF');
});

it('loads WordPress fixture options', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'wordpress' => [
        'options' => [
            'blogdescription' => 'A test site',
            'users_can_register' => false,
        ],
    ],
];
PHP);

    expect((new ConfigLoader)->load($this->workspace)->wordpressOptions())->toBe([
        'blogdescription' => 'A test site',
        'users_can_register' => false,
    ]);
});

it('rejects a list of WordPress fixture options', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return ['wordpress' => ['options' => ['invalid']]];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'wordpress.options must be an associative array.');

it('loads plugin target with code and browser tests', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],
    'plugin' => ['path' => 'my-plugin'],
    'matrix' => [
        'wordpress' => ['^6.9', '^7.0'],
        'profiles' => [
            'default' => ['tests' => ['code', 'browser']],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);

    expect($config->target())->toBe('plugin')
        ->and($config->tests())->toBe(['code', 'browser'])
        ->and($config->pluginPath())->toBe('my-plugin')
        ->and($config->matrixCombinations())->toHaveCount(2)
        ->and($config->matrixCombinations()[0]->tests())->toBe(['code', 'browser']);
});

it('uses the top-level tests and wordpress settings for the default matrix', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'site' => ['url' => 'https://example.test'],
    'wordpress' => ['version' => '^6.9'],
    'plugin' => ['path' => '.'],
];
PHP);

    $combination = (new ConfigLoader)->load($this->workspace)->matrixCombinations()[0];

    expect($combination->tests())->toBe(['browser'])
        ->and($combination->wordpress())->toBe('^6.9');
});

it('requires a url for site browser tests', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'site',
    'tests' => ['browser'],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'site.url is required');

it('defaults the plugin path to the current directory', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);

    expect($config->pluginPath())->toBe('.');
});

it('rejects invalid plugin dependency definitions', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
    'plugin' => ['path' => '.'],
    'plugins' => [
        'dependencies' => [
            ['url' => 'not-a-url'],
        ],
    ],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'Each plugin dependency');

it('applies environment overrides', function (): void {
    putenv('WP_PEST_TARGET=plugin');
    putenv('WP_PEST_TESTS=browser');
    putenv('WP_PEST_PLUGIN_PATH=plugin-from-env');

    try {
        $config = (new ConfigLoader)->load($this->workspace);

        expect($config->target())->toBe('plugin')
            ->and($config->tests())->toBe(['browser'])
            ->and($config->pluginPath())->toBe('plugin-from-env');
    } finally {
        putenv('WP_PEST_TARGET');
        putenv('WP_PEST_TESTS');
        putenv('WP_PEST_PLUGIN_PATH');
    }
});
