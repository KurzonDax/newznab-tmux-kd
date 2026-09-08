<?php

declare(strict_types=1);

namespace App\Services\ReleaseRepair;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
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
    ) {}

    public static function acquire(Release $release): ?self
    {
        if (! self::isSupported()) {
            return new self((int) $release->id, null);
        }

        $claimedAt = now();
        $token = Schema::hasColumn('releases', 'recovery_claim_token') ? (string) Str::uuid() : null;
        $claimed = self::applyAvailable(Release::query()->whereKey($release->id))
            ->update([self::COLUMN => $claimedAt, ...($token === null ? [] : ['recovery_claim_token' => $token])]);

        return $claimed === 1
            ? new self((int) $release->id, $claimedAt, $token)
            : null;
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
