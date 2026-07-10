<?php

declare(strict_types=1);

use CrossPeak\WpPest\Support\SiteDetector;

it('detects wordpress in the current directory', function (): void {
    file_put_contents($this->workspace.'/wp-load.php', '<?php');

    expect((new SiteDetector)->detect($this->workspace))->toBe($this->workspace);
});

it('detects common wordpress subdirectories', function (): void {
    mkdir($this->workspace.'/web/wp', 0775, true);
    file_put_contents($this->workspace.'/web/wp/wp-load.php', '<?php');

    expect((new SiteDetector)->detect($this->workspace))->toBe($this->workspace.'/web/wp');
});
