<?php

use CrossPeak\WpPest\Runtime\Constants;
use PHPUnit\Framework\SkippedWithMessageException;

use function CrossPeak\WpPest\requireEnvironment;

it('defines shared constants before the plugin loads', function () {
    expect(WP_PEST_SHARED_AT_PLUGIN_LOAD)->toBe(wp_pest()->config()->wordpressConstants()['WP_PEST_TEST_SHARED'])
        ->and(fn () => Constants::apply(['WP_PEST_TEST_SHARED' => 'different']))->toThrow(RuntimeException::class, 'WP_PEST_TEST_SHARED');
});

it('observes real WordPress versions and activation changes', function () {
    $environment = requireEnvironment(plugins: ['plugin-under-test'], wordpress: '>=6.9', php: '>=8.4', database: 'sqlite');
    expect($environment->php)->toBe(PHP_VERSION)->and($environment->wordpress)->toBe($GLOBALS['wp_version'])
        ->and($environment->databaseVersion)->toBeString();
    deactivate_plugins('plugin-under-test/plugin.php');
    expect(wp_pest()->hasPlugin('plugin-under-test'))->toBeFalse()
        ->and(fn () => requireEnvironment(plugins: ['plugin-under-test']))->toThrow(SkippedWithMessageException::class, 'not active');
});
