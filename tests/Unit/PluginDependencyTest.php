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
        'slug' => 'sample-plugin',
        'version' => '1.2.3',
        'url' => 'https://plugins.example.test/sample-plugin-1.2.3.zip',
    ]);

    expect($dependency->isUrl())->toBeTrue()
        ->and($dependency->slug())->toBe('sample-plugin')
        ->and($dependency->source())->toBe('https://plugins.example.test/sample-plugin-1.2.3.zip')
        ->and($dependency->version())->toBe('1.2.3')
        ->and($dependency->packageName())->toStartWith('wp-pest-plugin/sample-plugin-');
});

it('infers plugin slugs and versions from ZIP URLs', function (): void {
    $dependency = PluginDependency::from('https://plugins.example.test/sample-plugin-1.2.3.zip');

    expect($dependency->slug())->toBe('sample-plugin')
        ->and($dependency->version())->toBe('1.2.3');
});

it('preserves local plugin paths', function (): void {
    $dependency = PluginDependency::from('../shared/plugin-one');

    expect($dependency->isLocalPath())->toBeTrue()
        ->and($dependency->slug())->toBe('plugin-one')
        ->and($dependency->source())->toBe('../shared/plugin-one');
});
