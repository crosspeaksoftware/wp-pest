<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Runtime\Requirements;
use CrossPeak\WpPest\Runtime\VersionConstraint;
use CrossPeak\WpPest\Runtime\WpPest;
use CrossPeak\WpPest\Support\Process;
use PHPUnit\Framework\SkippedWithMessageException;

beforeEach(function () {
    file_put_contents($this->workspace.'/tests/wp-pest.php', "<?php return ['target' => 'plugin', 'tests' => ['browser'], 'plugins' => ['dependencies' => [['slug' => 'example', 'version' => '99.0', 'path' => '/tmp/example']]]];");
    $this->snapshot = ['php' => '8.4.99', 'wordpress' => '6.9.10', 'database' => 'mariadb', 'database_version' => '11.4.1', 'plugins' => [
        'dependency-0' => ['file' => 'dependency-0/example.php', 'version' => '3.1.3', 'active' => true],
    ]];
    $this->runtime = new WpPest((new ConfigLoader)->load($this->workspace), $this->workspace, fn () => $this->snapshot);
});

it('reports the sandbox versions instead of configured values or host PHP', function () {
    expect($this->runtime->phpVersion())->toBe('8.4.99')
        ->and($this->runtime->wordpressVersion())->toBe('6.9.10')
        ->and($this->runtime->configuredPluginVersion('example'))->toBe('99.0')
        ->and($this->runtime->pluginVersion('example'))->toBe('3.1.3')
        ->and($this->runtime->hasPlugin('example'))->toBeTrue()
        ->and($this->runtime->databaseType())->toBe('mariadb')
        ->and($this->runtime->phpVersion('code'))->toBe(PHP_VERSION);
    $this->snapshot['plugins']['dependency-0']['active'] = false;
    expect($this->runtime->hasPlugin('example'))->toBeFalse()
        ->and($this->runtime->pluginVersion('example'))->toBe('3.1.3');
});

it('skips legitimate mismatches with observed values in the reason', function () {
    Requirements::check($this->runtime, ['example' => '>=3.0'], wordpress: '>=6.9', php: '>=8.4', database: 'mysql');
    expect(fn () => Requirements::check($this->runtime, ['example' => '>=4.0']))->toThrow(SkippedWithMessageException::class, '3.1.3')
        ->and(fn () => Requirements::check($this->runtime, ['optional']))->toThrow(SkippedWithMessageException::class, 'optional')
        ->and(fn () => Requirements::check($this->runtime, wordpress: '>=7.0'))->toThrow(SkippedWithMessageException::class, '6.9.10')
        ->and(fn () => Requirements::check($this->runtime, php: '>=8.5'))->toThrow(SkippedWithMessageException::class, '8.4.99')
        ->and(fn () => Requirements::check($this->runtime, database: 'sqlite'))->toThrow(SkippedWithMessageException::class, 'mariadb');
});

it('fails missing configured dependencies before skipping optional requirements', function () {
    $this->snapshot['plugins'] = [];
    expect(fn () => Requirements::check($this->runtime, ['optional']))->toThrow(RuntimeException::class, 'Configured dependency is missing or inactive');
});

it('rejects unsupported constraints rather than silently skipping a test', function () {
    expect(fn () => Requirements::check($this->runtime, ['optional' => '^3.0']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Requirements::check($this->runtime, database: 'mongo'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Requirements::check($this->runtime, ['invalid slug']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->runtime->environment('typo'))->toThrow(InvalidArgumentException::class)
        ->and(VersionConstraint::matches('3.1.3-beta1', '<3.1.3'))->toBeTrue();
});

it('reads actual MySQL and MariaDB versions including the compatibility prefix', function (string $server, string $engine, string $version) {
    mkdir($this->workspace.'/wp-admin/includes', 0775, true);
    file_put_contents($this->workspace.'/wp-admin/includes/plugin.php', '<?php');
    $output = (new Process)->capture([
        PHP_BINARY, __DIR__.'/../fixtures/environment-probe.php', __DIR__.'/../../src/Runtime/environment.php', $this->workspace, $server,
    ]);
    $environment = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($environment['database'])->toBe($engine)->and($environment['database_version'])->toBe($version);
})->with([
    ['5.5.5-11.4.7-MariaDB-log', 'mariadb', '11.4.7'],
    ['8.4.0-ubuntu', 'mysql', '8.4.0'],
    ['5.5.5-0ubuntu', 'mysql', '5.5.5'],
]);
