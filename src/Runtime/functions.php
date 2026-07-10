<?php

declare(strict_types=1);

use CrossPeak\WpPest\Runtime\WpPest;

if (! function_exists('wp_pest')) {
    function wp_pest(): WpPest
    {
        return WpPest::fromCurrentWorkingDirectory();
    }
}

if (getenv('WP_PEST_AUTO_BOOTSTRAP_WORDPRESS') === '1') {
    wp_pest()->bootstrapWordPress();
}
