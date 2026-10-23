<?php

declare(strict_types=1);

namespace App\Services\ProviderGateway;

/** Why the gateway declined to send a request. */
enum ShortCircuitReason: string
{
    case BreakerOpen = 'breaker_open';
    case TrialClaimed = 'trial_claimed';
    case BudgetRefused = 'budget_refused';
    case PacerSkip = 'pacer_skip';
    case LockTimeout = 'lock_timeout';
}
