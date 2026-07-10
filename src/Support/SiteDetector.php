<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Support;

final class SiteDetector
{
    public function detect(string $start): ?string
    {
        $directory = realpath($start) ?: $start;

        while ($directory !== dirname($directory)) {
            if (is_file($directory . DIRECTORY_SEPARATOR . 'wp-load.php')) {
                return $directory;
            }

            foreach (['web/wp', 'public/wp', 'wordpress', 'wp'] as $candidate) {
                $path = $directory . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($path . DIRECTORY_SEPARATOR . 'wp-load.php')) {
                    return $path;
                }
            }

            $directory = dirname($directory);
        }

        return null;
    }
}
