<?php

use CrossPeak\WpPest\WordPressTestCase;

pest()->extend(WordPressTestCase::class)->in('Code');

if (! wp_pest()->bootstrapWordPress()) {
    throw new RuntimeException('Could not bootstrap the integration fixture.');
}

add_filter('wp_pest_baseline', static fn () => 'baseline');
