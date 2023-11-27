<?php

declare(strict_types=1);

namespace Darwin\Models;

/** @psalm-immutable */
final readonly class Country
{
    /**
     * @param positive-int     $identifier
     * @param non-empty-string $name
     */
    public function __construct(
        public int $identifier,
        public string $name,
    ) {
    }
}
