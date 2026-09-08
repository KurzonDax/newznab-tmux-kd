<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class RecoveryInspection
{
    private function __construct(private object $publication, private RecoveryLease $lease, private ?string $additionalToken) {}

    public static function acquire(object $publication, ?string $additionalToken = null): ?self
    {
        return DB::transaction(function () use ($publication, $additionalToken): ?self {
            $release = Release::query()->whereKey($publication->releases_id)->where('guid', $publication->guid)->lockForUpdate()->first();
            if ($release === null || ! self::additionalOwned($release, $additionalToken)) {
                return null;
            }
            $lease = RecoveryLease::acquire($release, additionalToken: $additionalToken);

            return $lease === null ? null : new self($publication, $lease, $additionalToken);
        }, 1);
    }

    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    public function mutate(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            $release = Release::query()->whereKey($this->publication->releases_id)->where('guid', $this->publication->guid)->lockForUpdate()->first();
            $current = DB::table('obfuscation_recovery_publications')->where('id', $this->publication->id)->lockForUpdate()->first();
            if ($release === null || $current === null || $current->state !== 'published' || $current->deleted_at !== null
                || $current->releases_id != $release->id || $current->guid !== $release->guid
                || $current->sealed_plan !== $this->publication->sealed_plan || ! $this->lease->owns((int) $release->id)
                || ! self::additionalOwned($release, $this->additionalToken)) {
                throw new \RuntimeException('recovery_inspection_claim_lost');
            }

            return $operation();
        }, 1);
    }

    public function assertPublication(object $publication): void
    {
        if ((int) $publication->id !== (int) $this->publication->id
            || (int) $publication->releases_id !== (int) $this->publication->releases_id
            || $publication->guid !== $this->publication->guid
            || $publication->sealed_plan !== $this->publication->sealed_plan) {
            throw new \RuntimeException('recovery_inspection_scope_mismatch');
        }
    }

    public function release(): void
    {
        $this->lease->release();
    }

    private static function additionalOwned(Release $release, ?string $token): bool
    {
        $claimedAt = $release->{ReleaseClaimant::CLAIMED_AT_COLUMN};
        $fresh = $claimedAt !== null && Carbon::parse($claimedAt)->greaterThanOrEqualTo(ReleaseClaimant::claimStaleBefore());

        return $token === null ? ! $fresh : $fresh && $release->{ReleaseClaimant::CLAIM_TOKEN_COLUMN} === $token;
    }
}
