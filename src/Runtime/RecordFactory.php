<?php

declare(strict_types=1);

namespace CrossPeak\WpPest\Runtime;

use InvalidArgumentException;

abstract class RecordFactory
{
    /** @param array<string, mixed> $attributes */
    abstract public function create(array $attributes = []): int;

    abstract protected function get(int $id): object;

    /** @param array<string, mixed> $attributes
     * @return list<int>
     */
    public function createMany(int $count, array $attributes = []): array
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Factory counts must be zero or greater.');
        }
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = $this->create($attributes);
        }

        return $ids;
    }

    /** @param array<string, mixed> $attributes */
    public function createAndGet(array $attributes = []): object
    {
        return $this->get($this->create($attributes));
    }
}
