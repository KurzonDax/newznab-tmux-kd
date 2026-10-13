<?php

declare(strict_types=1);

namespace App\Services\DecisionModel\Exceptions;

use RuntimeException;

/**
 * A decision could not be reached. The reason is a short code a log row can hold.
 */
abstract class DecisionModelException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason);
    }
}
