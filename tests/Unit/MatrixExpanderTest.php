<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;

it('expands every wordpress plugin and profile combination', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'plugins' => ['dependencies' => ['akismet']],
    'matrix' => [
        'wordpress' => ['6.9.*', '7.0.*'],
        'plugins' => [
            'sample-plugin' => [
                ['version' => '1.2.3', 'url' => 'https://example.test/sample-plugin-1.2.3.zip'],
                ['version' => '1.2.4', 'url' => 'https://example.test/sample-plugin-1.2.4.zip'],
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
        ->and($combinations[0]->name())->toBe('code--wp-6.9.x--sample-plugin-1.2.3--query-monitor-3.19.0')
        ->and($combinations[0]->pluginVersions())->toBe([
            'akismet' => '*',
            'sample-plugin' => '1.2.3',
            'query-monitor' => '3.19.0',
        ])
        ->and($combinations[8]->tests())->toBe(['browser'])
        ->and($config->wordpressVersion())->toBe('6.9.*')
        ->and(array_map(static fn ($plugin): string => $plugin->slug(), $config->dependencyPlugins()))
        ->toBe(['akismet', 'sample-plugin', 'query-monitor']);
});

it('applies partial exclusion rules before enforcing the combination limit', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['6.9', '7.0'],
        'plugins' => ['sample-plugin' => ['1.2.3', '1.2.4']],
        'profiles' => [
            'default' => ['tests' => ['code']],
            'compat' => ['tests' => ['code'], 'groups' => ['compat']],
        ],
        'exclude' => [
            ['wordpress' => '6.9'],
            ['profile' => 'compat', 'plugins' => ['sample-plugin' => '1.2.3']],
        ],
        'max_combinations' => 3,
    ],
];
PHP);

    $combinations = (new ConfigLoader)->load($this->workspace)->matrixCombinations();

    expect(array_map(static fn ($combination): string => $combination->name(), $combinations))->toBe([
        'default--wp-7.0--sample-plugin-1.2.3',
        'default--wp-7.0--sample-plugin-1.2.4',
        'compat--wp-7.0--sample-plugin-1.2.4',
    ]);
});

it('supports profile plugin inheritance overrides and groups', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
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

it('uses profile-overridden plugin versions in generated matrix names', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'matrix' => [
        'wordpress' => ['6.9'],
        'plugins' => ['sample-plugin' => ['1.2.4']],
        'profiles' => [
            'signature-compatible' => [
                'tests' => ['browser'],
                'plugins' => [[
                    'slug' => 'sample-plugin',
                    'version' => '2.0.0',
                    'url' => 'https://example.test/sample-plugin-2.0.0.zip',
                ]],
            ],
        ],
    ],
];
PHP);

    $combination = (new ConfigLoader)->load($this->workspace)->matrixCombinations()[0];

    expect($combination->name())->toBe('signature-compatible--wp-6.9--sample-plugin-2.0.0')
        ->and($combination->pluginVersions())->toBe(['sample-plugin' => '2.0.0']);
});

it('expands configured PHP versions and includes them in matrix names', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'matrix' => [
        'wordpress' => ['6.9'],
        'php' => ['8.4', '8.5'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    $combinations = (new ConfigLoader)->load($this->workspace)->matrixCombinations();

    expect($combinations)->toHaveCount(2)
        ->and($combinations[0]->name())->toBe('default--wp-6.9--php-8.4')
        ->and($combinations[0]->phpVersion())->toBe('8.4')
        ->and($combinations[1]->name())->toBe('default--wp-6.9--php-8.5')
        ->and($combinations[1]->all()['php'])->toBe('8.5');
});

it('rejects invalid PHP matrix versions', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'matrix' => [
        'php' => ['8.5.1'],
        'profiles' => ['default' => ['tests' => ['browser']]],
    ],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'matrix.php must be a non-empty list');

it('rejects exclusions that reference unknown dimensions', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'plugin' => ['path' => '.'],
    'matrix' => [
        'wordpress' => ['7.0'],
        'plugins' => ['sample-plugin' => ['1.2.4']],
        'profiles' => ['default' => ['tests' => ['code']]],
        'exclude' => [['plugins' => ['sample-plugin' => '0.9.0']]],
    ],
];
PHP);

    (new ConfigLoader)->load($this->workspace);
})->throws(ValidationException::class, 'unknown version 0.9.0');

it('rejects matrices above the configured post-exclusion limit', function (): void {
    file_put_contents($this->workspace.'/tests/wp-pest.php', <<<'PHP'
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
