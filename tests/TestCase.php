<?php

declare(strict_types=1);

namespace Tests;

use CrossPeak\WpPest\Config\Config;
use CrossPeak\WpPest\Sandbox\PluginFixture;
use FilesystemIterator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class TestCase extends BaseTestCase
{
    protected string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/wp-pest-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/tests', 0775, true);
    }

    protected function tearDown(): void
    {
        if (! isset($this->workspace)) {
            parent::tearDown();

            return;
        }

        $fixture = (new PluginFixture(
            new Config([]), $this->workspace,
        ))->pathFor('unused');
        foreach ([$this->workspace, dirname($fixture, 2)] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isLink() || $file->isFile()) {
                    unlink($file->getPathname());
                } else {
                    rmdir($file->getPathname());
                }
            }

            rmdir($directory);
        }

        parent::tearDown();
    }
}
