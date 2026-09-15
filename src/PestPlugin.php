<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

use CrossPeak\WpPest\Browser\WpPestHttpServer;
use Pest\Browser\Configuration;
use Pest\Contracts\Plugins\Bootable;

final class PestPlugin implements Bootable
{
    public function boot(): void
    {
        // Pest loads Composer files autoloads before plugin boot. Runtime helpers
        // are registered from src/Runtime/functions.php.

        // Browser testing is optional, so keep wp-pest usable without the
        // Pest Browser plugin installed.
        if (class_exists(Configuration::class)) {
            \pest()->browser()->httpServer(static fn (): WpPestHttpServer => new WpPestHttpServer);
        }
    }
}
