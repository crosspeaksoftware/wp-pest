<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

final class Factory
{
    public readonly UserFactory $user;

    public function __construct()
    {
        $this->user = new UserFactory;
    }
}
