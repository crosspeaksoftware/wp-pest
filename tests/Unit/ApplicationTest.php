<?php

declare(strict_types=1);

use CrossPeak\WpPest\Cli\Application;

it('initializes site mode by default', function (): void {
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    $config = require $this->workspace.'/wp-pest.php';

    expect($exitCode)->toBe(0)
        ->and($config['target'])->toBe('site')
        ->and($config)->not->toHaveKeys(['plugin', 'plugins', 'sandbox', 'wordpress'])
        ->and($this->workspace.'/tests/Pest.php')->toBeFile()
        ->and($this->workspace.'/tests/Code/SiteTest.php')->toBeFile()
        ->and($this->workspace.'/tests/Browser/SiteTest.php')->toBeFile()
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toContain('.wp-pest/')
        ->and(file_get_contents($this->workspace.'/.gitignore'))->toContain('tests/Browser/Screenshots/');
});

it('initializes plugin mode when requested', function (): void {
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init', '--target=plugin']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    $config = require $this->workspace.'/wp-pest.php';

    expect($exitCode)->toBe(0)
        ->and($config['target'])->toBe('plugin')
        ->and($config['plugin']['path'])->toBe('.')
        ->and($config['sandbox']['matrix'])->toHaveKeys(['php83-wp-latest', 'php84-wp-latest'])
        ->and($this->workspace.'/tests/Code/PluginTest.php')->toBeFile()
        ->and($this->workspace.'/tests/Browser/PluginTest.php')->toBeFile();
});

it('rejects invalid init targets', function (): void {
    $previous = getcwd();
    chdir($this->workspace);
    ob_start();

    try {
        $exitCode = (new Application)->run(['wp-pest', 'init', '--target=other']);
    } finally {
        ob_end_clean();
        chdir($previous);
    }

    expect($exitCode)->toBe(1)
        ->and($this->workspace.'/wp-pest.php')->not->toBeFile();
});
