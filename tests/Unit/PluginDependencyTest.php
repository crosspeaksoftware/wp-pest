<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\PluginDependency;

it('normalizes WPackagist plugin slugs', function (): void {
    $dependency = PluginDependency::from('query-monitor');

    expect($dependency->isLocalPath())->toBeFalse()
        ->and($dependency->isUrl())->toBeFalse()
        ->and($dependency->slug())->toBe('query-monitor')
        ->and($dependency->packageName())->toBe('wpackagist-plugin/query-monitor')
        ->and($dependency->version())->toBe('*');
});

it('normalizes explicit ZIP plugin definitions', function (): void {
    $dependency = PluginDependency::from([
        'slug' => 'gravityforms',
        'version' => '2.10.5',
        'url' => 'https://plugins.example.test/gravityforms-2.10.5.zip',
    ]);

    expect($dependency->isUrl())->toBeTrue()
        ->and($dependency->slug())->toBe('gravityforms')
        ->and($dependency->source())->toBe('https://plugins.example.test/gravityforms-2.10.5.zip')
        ->and($dependency->version())->toBe('2.10.5')
        ->and($dependency->packageName())->toStartWith('wp-pest-plugin/gravityforms-');
});

it('infers plugin slugs and versions from ZIP URLs', function (): void {
    $dependency = PluginDependency::from('https://plugins.example.test/gravityforms-2.10.5.zip');

    expect($dependency->slug())->toBe('gravityforms')
        ->and($dependency->version())->toBe('2.10.5');
});

it('preserves local plugin paths', function (): void {
    $dependency = PluginDependency::from('../shared/plugin-one');

    expect($dependency->isLocalPath())->toBeTrue()
        ->and($dependency->slug())->toBe('plugin-one')
        ->and($dependency->source())->toBe('../shared/plugin-one');
});
