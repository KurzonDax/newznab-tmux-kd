<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\Par2Sidecar\SidecarMutationProtection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A short-lived, row-level lease shared by both recovery engines.
 *
 * Repair and header re-scan use the same column because neither may work a release while the
 * other does. The stale boundary deliberately matches additional processing: a crashed pass
 * eventually stops shielding the release from future recovery and destructive sweeps.
 */
final class RecoveryLease
{
    public const string COLUMN = 'recovery_claimed_at';

    private function __construct(
        private readonly int $releaseId,
        private readonly ?Carbon $claimedAt,
        private readonly ?string $token = null,
        private readonly ?int $sidecarOperationId = null,
    ) {}

    public static function acquire(Release $release, ?int $sidecarOperationId = null, ?string $additionalToken = null): ?self
    {
        if (! self::isSupported()) {
            return new self((int) $release->id, null);
        }

        $claimedAt = now();
        $token = Schema::hasColumn('releases', 'recovery_claim_token') ? (string) Str::uuid() : null;
        $query = self::applyAvailable(Release::query()->whereKey($release->id));
        if (Schema::hasColumn('releases', ReleaseClaimant::CLAIMED_AT_COLUMN)) {
            if ($additionalToken === null) {
                $query->where(fn (Builder $q) => $q->whereNull(ReleaseClaimant::CLAIMED_AT_COLUMN)
                    ->orWhere(ReleaseClaimant::CLAIMED_AT_COLUMN, '<', ReleaseClaimant::claimStaleBefore()));
            } else {
                $query->where(ReleaseClaimant::CLAIM_TOKEN_COLUMN, $additionalToken)
                    ->where(ReleaseClaimant::CLAIMED_AT_COLUMN, '>=', ReleaseClaimant::claimStaleBefore());
            }
        }
        SidecarMutationProtection::apply($query, 'releases', $sidecarOperationId);
        $claimed = $query
            ->update([self::COLUMN => $claimedAt, ...($token === null ? [] : ['recovery_claim_token' => $token])]);

        if ($claimed !== 1) {
            return null;
        }
        $lease = new self((int) $release->id, $claimedAt, $token, $sidecarOperationId);

        return $lease;
    }

    /**
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public static function applyAvailable(Builder $query, string $table = 'releases'): Builder
    {
        if (! self::isSupported()) {
            return $query;
        }

        $column = $table.'.'.self::COLUMN;

        return $query->where(static function (Builder $leaseQuery) use ($column): void {
            $leaseQuery
                ->whereNull($column)
                ->orWhere($column, '<', ReleaseClaimant::claimStaleBefore());
        });
    }

    public function owns(int $releaseId): bool
    {
        if ($releaseId !== $this->releaseId) {
            return false;
        }
        if ($this->claimedAt === null) {
            return ! self::isSupported() && Release::query()->whereKey($releaseId)->exists();
        }

        return $this->claimedAt->greaterThanOrEqualTo(ReleaseClaimant::claimStaleBefore())
            && Release::query()->whereKey($releaseId)->where(self::COLUMN, $this->claimedAt)
                ->when($this->token !== null, fn (Builder $query) => $query->where('recovery_claim_token', $this->token))->exists();
    }

    public function operationId(): ?int
    {
        return $this->sidecarOperationId;
    }

    public function release(): void
    {
        if ($this->claimedAt === null) {
            return;
        }

        Release::query()
            ->whereKey($this->releaseId)
            ->where(self::COLUMN, $this->claimedAt)
            ->when($this->token !== null, fn (Builder $query) => $query->where('recovery_claim_token', $this->token))
            ->update([self::COLUMN => null, ...($this->token === null ? [] : ['recovery_claim_token' => null])]);
    }

    public static function isSupported(): bool
    {
        return Schema::hasTable('releases') && Schema::hasColumn('releases', self::COLUMN);
    }
}
