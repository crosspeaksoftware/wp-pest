<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
    'wordpress' => [
        'version' => '^6.9',
        'context' => getenv('WP_PEST_INTEGRATION_CONTEXT') ?: 'front',
    ],
];
