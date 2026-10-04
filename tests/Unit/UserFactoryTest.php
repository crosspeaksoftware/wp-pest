<?php

declare(strict_types=1);

use CrossPeak\WpPest\Runtime\UserFactory;

it('requires WordPress before creating users', function (): void {
    expect(fn (): int => (new UserFactory)->create())
        ->toThrow(RuntimeException::class, 'WordPress must be bootstrapped');
});
