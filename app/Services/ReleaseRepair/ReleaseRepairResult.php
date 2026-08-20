<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Enums\ReleaseRepairOutcome;

/**
 * What one release's repair attempt did.
 */
final readonly class ReleaseRepairResult
{
    public function __construct(
        public ReleaseRepairOutcome $outcome,
        public float $completionBefore,
        public float $completionAfter,
        public int $segmentsAdded,
        public int $articlesProbed,
        public bool $nzbRewritten,
        public bool $requeuedForAdditionalProcessing,
        public string $reason,
    ) {}
}
