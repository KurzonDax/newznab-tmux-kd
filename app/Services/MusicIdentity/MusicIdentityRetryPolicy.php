<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

final readonly class MusicIdentityRetryPolicy
{
    public function nextAttemptAt(int $attemptCount): \DateTimeInterface
    {
        $initial = max(1, (int) config('music-identity.retry.initial_seconds', 60));
        $maximum = max($initial, (int) config('music-identity.retry.maximum_seconds', 3600));
        $exponent = min(30, max(0, $attemptCount));
        $delay = min($maximum, $initial * (2 ** $exponent));

        return now()->addSeconds((int) $delay);
    }
}
