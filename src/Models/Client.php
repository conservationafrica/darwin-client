<?php

declare(strict_types=1);

namespace Darwin\Models;

final readonly class Client
{
    public function __construct(
        public int $id,
        public string $emailAddress,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
