<?php

declare(strict_types=1);

use CrossPeak\WpPest\Config\ConfigLoader;
use CrossPeak\WpPest\Config\ValidationException;
use CrossPeak\WpPest\Sandbox\SandboxManager;

it('selects all matrix entries', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => '.'],
    'sandbox' => [
        'matrix' => [
            'php83' => ['php' => '8.3'],
            'php84' => ['php' => '8.4'],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);

    expect($manager->matrixNames('all'))->toBe(['php83', 'php84']);
});

it('rejects unknown matrix entries', function (): void {
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => '.'],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);

    $manager->matrixNames('missing');
})->throws(ValidationException::class, 'Unknown sandbox matrix entry');

it('writes compose files for matrix entries', function (): void {
    mkdir($this->workspace.'/plugin');
    file_put_contents($this->workspace.'/wp-pest.php', <<<'PHP'
<?php

return [
    'target' => 'plugin',
    'tests' => ['browser'],
    'plugin' => ['path' => 'plugin'],
    'sandbox' => [
        'matrix' => [
            'php83' => [
                'php' => '8.3',
                'database' => 'mariadb:11',
                'port' => 8091,
            ],
        ],
    ],
];
PHP);

    $config = (new ConfigLoader)->load($this->workspace);
    $manager = new SandboxManager($config, $this->workspace);
    $path = $manager->writeCompose('php83');

    expect($path)->toBeFile()
        ->and(file_get_contents($path))->toContain('wordpress:php8.3-apache')
        ->and(file_get_contents($path))->toContain('"8091:80"')
        ->and(file_get_contents($path))->toContain('/plugin:/var/www/html/wp-content/plugins/plugin-under-test');
});
