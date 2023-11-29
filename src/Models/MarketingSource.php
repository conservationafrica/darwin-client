<?php

declare(strict_types=1);

namespace Darwin\Models;

final readonly class MarketingSource
{
    public function __construct(
        public int $sourceId,
        public string $name,
        public int $categoryId,
        public string $categoryName,
        public bool $isActive,
        public bool $isPublic,
    ) {
    }
}
