<?php

declare(strict_types=1);

use CrossPeak\WpPest\Browser\WpPestHttpServer;
use Pest\Browser\ServerManager;

it('registers the wp-pest HTTP server with Pest Browser', function (): void {
    expect(ServerManager::instance()->http())->toBeInstanceOf(WpPestHttpServer::class);
});

it('resolves browser-relative paths against the WordPress URL', function (): void {
    $server = new WpPestHttpServer(
        static fn (string $path): string => 'http://wordpress.test/subdirectory/'.ltrim($path, '/')
    );

    expect($server->rewrite('/wp-admin/plugins.php'))
        ->toBe('http://wordpress.test/subdirectory/wp-admin/plugins.php');
});

it('initializes the wp-pest sandbox from the browser server bootstrap', function (): void {
    $resolvedPaths = [];
    $server = new WpPestHttpServer(
        static function (string $path) use (&$resolvedPaths): string {
            $resolvedPaths[] = $path;

            return 'http://wordpress.test/'.ltrim($path, '/');
        }
    );

    $server->bootstrap();

    expect($resolvedPaths)->toBe(['/']);
});
