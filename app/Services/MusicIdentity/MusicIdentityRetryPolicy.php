<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use Illuminate\Support\Carbon;

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

    /**
     * When to resolve a release again that has no album yet; null when it is too old to gain one.
     * The release is looked at again when it is twice its present age, and never sooner than
     * `music-identity.recheck.minimum_seconds` from now (#1277).
     */
    public function recheckAt(mixed $postdate): ?\DateTimeInterface
    {
        $age = $this->ageSeconds($postdate);
        if ($age === null) {
            return null;
        }

        $delay = max($this->minimumSeconds(), $age);
        if ($age + $delay > $this->maximumAgeSeconds()) {
            return null;
        }

        return now()->addSeconds($delay);
    }

    /** When to try a failed later look again; null once the release is too old to gain an album. */
    public function recheckRetryAt(mixed $postdate, int $attemptCount): ?\DateTimeInterface
    {
        $age = $this->ageSeconds($postdate);
        if ($age === null || $age > $this->maximumAgeSeconds()) {
            return null;
        }

        return $this->nextAttemptAt($attemptCount);
    }

    /** Seconds from the release's post date to now, 0 for a post date in the future; null without one. */
    private function ageSeconds(mixed $postdate): ?int
    {
        if ($postdate === null) {
            return null;
        }
        $posted = $postdate instanceof \DateTimeInterface ? Carbon::instance($postdate) : Carbon::parse((string) $postdate);

        return max(0, (int) $posted->diffInSeconds(now(), absolute: false));
    }

    private function minimumSeconds(): int
    {
        return max(0, (int) config('music-identity.recheck.minimum_seconds', 86_400));
    }

    private function maximumAgeSeconds(): int
    {
        return max(0, (int) config('music-identity.recheck.maximum_age_seconds', 2_419_200));
    }
}
