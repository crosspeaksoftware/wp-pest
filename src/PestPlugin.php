<?php

declare(strict_types=1);

namespace CrossPeak\WpPest;

final class PestPlugin
{
    public function boot(): void
    {
        // Pest loads Composer files autoloads before plugin boot. Runtime helpers
        // are registered from src/Runtime/functions.php.
    }
}
