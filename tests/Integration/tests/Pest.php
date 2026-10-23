<?php

use CrossPeak\WpPest\WordPressTestCase;

pest()->extend(WordPressTestCase::class)->in('Code');

if (! wp_pest()->bootstrapWordPress()) {
    throw new RuntimeException('Could not bootstrap the integration fixture.');
}
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';

add_filter('wp_pest_baseline', static fn () => 'baseline');
