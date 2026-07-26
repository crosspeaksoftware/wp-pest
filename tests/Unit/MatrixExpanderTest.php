<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;

it('expands every wordpress plugin and profile combination', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'plugins' => ['dependencies' => ['akismet']],
    'matrix' => [
        'wordpress' => ['6.9.*', '7.0.*'],
        'plugins' => [
            'gravityforms' => [
                ['version' => '2.10.4', 'url' => 'https://example.test/gravityforms-2.10.4.zip'],
                ['version' => '2.10.5', 'url' => 'https://example.test/gravityforms-2.10.5.zip'],
            ],
            'query-monitor' => ['3.19.0', '3.20.0'],
        ],
        'profiles' => [
            'code' => ['tests' => ['code']],
            'browser' => ['tests' => ['browser']],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $combinations = $config->matrixCombinations();

    expect($combinations)->toHaveCount(16)
        ->and($combinations[0]->name())->toBe('code--wp-6.9.x--gravityforms-2.10.4--query-monitor-3.19.0')
        ->and($combinations[0]->pluginVersions())->toBe([
            'akismet' => '*',
            'gravityforms' => '2.10.4',
            'query-monitor' => '3.19.0',
        ])
        ->and($combinations[8]->tests())->toBe(['browser'])
        ->and($config->wordpressVersion())->toBe('6.9.*')
        ->and(array_map(static fn ($plugin): string => $plugin->slug(), $config->dependencyPlugins()))
        ->toBe(['akismet', 'gravityforms', 'query-monitor']);
});

it('applies partial exclusion rules before enforcing the combination limit', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['6.9', '7.0'],
        'plugins' => ['gravityforms' => ['2.10.4', '2.10.5']],
        'profiles' => [
            'default' => ['tests' => ['code']],
            'compat' => ['tests' => ['code'], 'groups' => ['compat']],
        ],
        'exclude' => [
            ['wordpress' => '6.9'],
            ['profile' => 'compat', 'plugins' => ['gravityforms' => '2.10.4']],
        ],
        'max_combinations' => 3,
    ],
];
PHP);

    $combinations = (new ConfigLoader)->load($this->workspace)->matrixCombinations();

    expect(array_map(static fn ($combination): string => $combination->name(), $combinations))->toBe([
        'default--wp-7.0--gravityforms-2.10.4',
        'default--wp-7.0--gravityforms-2.10.5',
        'compat--wp-7.0--gravityforms-2.10.5',
    ]);
});

it('supports profile plugin inheritance overrides and groups', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'plugins' => ['dependencies' => ['akismet', 'query-monitor']],
    'matrix' => [
        'wordpress' => ['7.0'],
        'profiles' => [
            'compat' => [
                'tests' => ['code'],
                'inherit_plugins' => false,
                'plugins' => [['slug' => 'woocommerce', 'version' => '10.0']],
                'exclude_plugins' => ['query-monitor'],
                'groups' => ['woocommerce'],
            ],
        ],
    ],
];
PHP);

    $combination = (new ConfigLoader)->load($this->workspace)->matrixCombinations()[0];

    expect($combination->pluginVersions())->toBe(['woocommerce' => '10.0'])
        ->and($combination->groups())->toBe(['woocommerce']);
});

it('rejects exclusions that reference unknown dimensions', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'plugins' => ['gravityforms' => ['2.10.5']],
        'profiles' => ['default' => ['tests' => ['code']]],
        'exclude' => [['plugins' => ['gravityforms' => '1.0']]],
    ],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'unknown version 1.0');

it('rejects matrices above the configured post-exclusion limit', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['6.9', '7.0'],
        'profiles' => ['default' => ['tests' => ['code']]],
        'max_combinations' => 1,
    ],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'more than 1 combinations');
