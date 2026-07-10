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
        'matrix' => [
            'php83-wp-latest' => [
                'php' => '8.3',
                'wordpress' => '^7.0',
                'database' => 'mariadb:11',
                'plugins' => [],
                'env' => [],
                'port' => 8080,
            ],
            'php84-wp-latest' => [
                'php' => '8.4',
                'wordpress' => '^7.0',
                'database' => 'mariadb:11',
                'plugins' => [],
                'env' => [],
                'port' => 8081,
            ],
        ],
    ],
];
