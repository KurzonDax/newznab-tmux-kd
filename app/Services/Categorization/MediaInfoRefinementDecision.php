<?php

declare(strict_types=1);

namespace App\Services\Categorization;

final readonly class MediaInfoRefinementDecision
{
    public function __construct(
        public int $categoryId,
        public string $rule,
    ) {}
}
