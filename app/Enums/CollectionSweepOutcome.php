<?php

declare(strict_types=1);

namespace App\Enums;

enum CollectionSweepOutcome: string
{
    case Exhausted = 'exhausted';
    case BudgetYielded = 'budget_yielded';
    case LeaseBusy = 'lease_busy';
    case LeaseLost = 'lease_lost';
    /** The previous pass started too recently for a new one. */
    case Resting = 'resting';
}
