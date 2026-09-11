<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\CollectionSweepOutcome;

final readonly class CollectionSweepResult
{
    public function __construct(public CollectionSweepOutcome $outcome, public int $examined, public int $deleted) {}
}
