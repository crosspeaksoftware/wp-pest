<?php

use CrossPeak\WpPest\WordPressTestCase;

if (wp_pest()->hasBrowserTests()) {
    pest()->browser()->timeout(60_000);
}

if (wp_pest()->shouldBootstrapWordPress() && ! wp_pest()->bootstrapWordPress()) {
    throw new RuntimeException('wp-pest could not bootstrap WordPress.');
}

pest()->extend(WordPressTestCase::class)->in('Code');
