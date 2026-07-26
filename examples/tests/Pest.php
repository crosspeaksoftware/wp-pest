<?php

pest()->browser()->timeout(60_000);

if (wp_pest()->shouldBootstrapWordPress() && ! wp_pest()->bootstrapWordPress()) {
    throw new RuntimeException('wp-pest could not bootstrap WordPress.');
}
