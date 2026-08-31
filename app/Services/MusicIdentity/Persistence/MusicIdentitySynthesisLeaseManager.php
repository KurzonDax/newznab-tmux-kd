<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

use App\Models\Release;
use App\Services\MusicIdentity\MusicIdentityRetryPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Durable pre-evidence lifecycle for lazy synthesis failures and leases.
 */
final readonly class MusicIdentitySynthesisLeaseManager
{
    public function __construct(private MusicIdentityRetryPolicy $retryPolicy) {}

    public function acquire(int $releaseId, string $workerToken): ?int
    {
        return DB::transaction(function () use ($releaseId, $workerToken): ?int {
            Release::query()->lockForUpdate()->findOrFail($releaseId);
            $algorithmVersion = $this->algorithmVersion();
            DB::table('release_music_synthesis_attempts')->insertOrIgnore([
                'releases_id' => $releaseId,
                'algorithm_version' => $algorithmVersion,
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $attempt = DB::table('release_music_synthesis_attempts')
                ->where('releases_id', $releaseId)
                ->where('algorithm_version', $algorithmVersion)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->next_attempt_at !== null && now()->isBefore($attempt->next_attempt_at)) {
                return null;
            }
            if ($attempt->lease_token !== $workerToken
                && $attempt->lease_expires_at !== null
                && now()->isBefore($attempt->lease_expires_at)) {
                return null;
            }

            DB::table('release_music_synthesis_attempts')
                ->where('id', $attempt->id)
                ->update([
                    'lease_token' => $workerToken,
                    'lease_expires_at' => $this->leaseExpiry(),
                    'updated_at' => now(),
                ]);

            return (int) $attempt->attempt_count;
        }, 3);
    }

    /**
     * A matching failure always changes the lease token and attempt count, so affected rows are reliable.
     */
    public function fail(
        int $releaseId,
        string $workerToken,
        int $attemptCount,
        string $operationalError,
    ): bool {
        return DB::table('release_music_synthesis_attempts')
            ->where('releases_id', $releaseId)
            ->where('algorithm_version', $this->algorithmVersion())
            ->where('lease_token', $workerToken)
            ->update([
                'attempt_count' => $attemptCount + 1,
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_attempt_at' => $this->retryPolicy->nextAttemptAt($attemptCount),
                'last_operational_error' => $operationalError,
                'updated_at' => now(),
            ]) === 1;
    }

    public function complete(int $releaseId, string $workerToken): void
    {
        DB::table('release_music_synthesis_attempts')
            ->where('releases_id', $releaseId)
            ->where('algorithm_version', $this->algorithmVersion())
            ->where('lease_token', $workerToken)
            ->delete();
    }

    private function algorithmVersion(): string
    {
        return (string) config('music-identity.algorithm_version', 'music-identity-v1');
    }

    private function leaseExpiry(): \DateTimeInterface
    {
        return now()->addSeconds(max(1, (int) config('music-identity.lease_seconds', 300)));
    }
}
