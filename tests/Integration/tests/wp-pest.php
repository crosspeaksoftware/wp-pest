<?php

return [
    'target' => 'plugin',
    'tests' => ['code'],
    'wordpress' => [
        'version' => '^6.9',
        'constants' => ['WP_PEST_TEST_SHARED' => ['text' => "quotes '\" and 東京", 'enabled' => true]],
        'context' => getenv('WP_PEST_INTEGRATION_CONTEXT') ?: 'front',
    ],
];
