<?php

declare(strict_types=1);

namespace App\Services\Categorization\Arbitration;

use App\Services\Categorization\ReleaseContext;

/**
 * A release whose category the pipeline was unsure of, and the confidence at
 * or above which a model answer about it is accepted.
 */
final readonly class CategoryArbitrationSubject
{
    public function __construct(
        public ReleaseContext $context,
        public float $applyThreshold,
    ) {}
}
