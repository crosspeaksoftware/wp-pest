<?php

declare(strict_types=1);

use CrossPeak\WpPest\Runtime\WpPest;

if (! function_exists('wp_pest')) {
    function wp_pest(): WpPest
    {
        return WpPest::fromCurrentWorkingDirectory();
    }
}
