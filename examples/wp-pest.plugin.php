<?php

declare(strict_types=1);

return [
    'target' => 'plugin',
    'tests' => ['code', 'browser'],

    'site' => [
        'url' => 'http://localhost',
        'path' => null,
    ],

    'wordpress' => [
        'version' => '^7.0',
    ],

    'plugin' => [
        'path' => '.',
    ],

    'plugins' => [
        'dependencies' => [],
    ],

    'sandbox' => [
        'path' => '.wp-pest/sandboxes',
    ],

    'matrix' => [
        'wordpress' => ['^6.9', '^7.0'],
        'plugins' => [
            'query-monitor' => ['3.19.0', '3.20.0'],
        ],
        'profiles' => [
            'default' => [
                'tests' => ['code', 'browser'],
                'plugins' => [],
                'inherit_plugins' => true,
                'exclude_plugins' => [],
                'groups' => [],
            ],
        ],
        'exclude' => [
            [
                'wordpress' => '^6.9',
                'plugins' => ['query-monitor' => '3.20.0'],
            ],
        ],
        'browser' => [
            'database' => 'mariadb:11',
            'env' => [],
            'port' => 8080,
        ],
        'max_combinations' => 100,
    ],
];
