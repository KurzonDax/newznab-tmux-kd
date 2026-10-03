<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SettingNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A secondary NNTP provider's forward header position in one group.
 *
 * Article numbers are per-server, so each secondary provider keeps its own position here,
 * keyed by `(usenet_groups_id, provider)`; provider 1 keeps its position on `usenet_groups`.
 * A secondary provider reads newest first and fills its backlog behind: completed ranges above
 * the position are parked in `usenet_group_provider_ingested_ranges`, and the position moves
 * when the backlog below them is complete.
 *
 * @property int $usenet_groups_id
 * @property string $provider
 * @property string $provider_host
 * @property int $last_record
 * @property string|null $last_record_postdate
 * @property string|null $last_advanced_at
 * @property int|null $server_first
 * @property int|null $server_last
 * @property string|null $server_checked_at
 */
class UsenetGroupProviderCursor extends Model
{
    /** Seeded value of `secondary_header_start_hours`: above `delaytime` (12) plus 2 hours. */
    public const int DEFAULT_START_HOURS = 36;

    protected $table = 'usenet_group_provider_cursors';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'usenet_groups_id' => 'integer',
            'last_record' => 'integer',
            'server_first' => 'integer',
            'server_last' => 'integer',
        ];
    }

    /**
     * How far back a secondary provider's first scan of a group starts. A retention window:
     * blank, missing or non-positive values resolve to the seeded default.
     */
    public static function startHours(): int
    {
        $hours = SettingNumber::int('secondary_header_start_hours', self::DEFAULT_START_HOURS);

        return $hours >= 1 ? $hours : self::DEFAULT_START_HOURS;
    }

    /**
     * Forget secondary providers' positions in one group, or in every group, so the next
     * discovery re-initialises them from the start-hours setting.
     */
    public static function forget(?int $groupId = null): void
    {
        DB::table('usenet_group_provider_ingested_ranges')->when($groupId !== null, static fn ($query) => $query->where('usenet_groups_id', $groupId))->delete();
        self::query()->when($groupId !== null, static fn ($query) => $query->where('usenet_groups_id', $groupId))->delete();
    }

    /**
     * Publish only covered article ranges, as {@see UsenetGroup::advanceLastRecordContiguously()}
     * does for provider 1. A range ahead of the cursor is parked until the gap before it fills.
     *
     * The binaries pass reads a secondary provider newest first, so the cursor moves only when
     * the lowest backlog range completes. `last_advanced_at` therefore stamps any completed
     * range, parked or not: the release wait and the header health probe read it as "still
     * scanning".
     */
    public static function advanceContiguously(int $groupId, string $provider, int $first, int $last, ?int $lastPostdate): int
    {
        return DB::transaction(function () use ($groupId, $provider, $first, $last, $lastPostdate): int {
            $cursor = self::query()->where('usenet_groups_id', $groupId)->where('provider', $provider)
                ->lockForUpdate()->first();
            // A missing row means a reset deleted it while this range was being read; the next
            // discovery re-initialises it.
            if ($cursor === null || $last <= $cursor->last_record || $last < $first) {
                return 0;
            }

            $postdate = $lastPostdate === null ? null : self::dateTimeFromTimestamp($lastPostdate);
            if ($cursor->last_record !== 0 && $first > $cursor->last_record + 1) {
                DB::table('usenet_group_provider_ingested_ranges')->insertOrIgnore([
                    'usenet_groups_id' => $groupId, 'provider' => $provider, 'first_record' => $first,
                    'last_record' => $last, 'last_record_postdate' => $postdate,
                ]);
                self::query()->where('usenet_groups_id', $groupId)->where('provider', $provider)
                    ->update(['last_advanced_at' => now()]);

                return 0;
            }

            $frontier = $last;
            $postdate ??= $cursor->last_record_postdate;
            while ($range = DB::table('usenet_group_provider_ingested_ranges')
                ->where('usenet_groups_id', $groupId)
                ->where('provider', $provider)
                ->where('first_record', '<=', $frontier + 1)
                ->orderBy('first_record')->lockForUpdate()->first()) {
                if ((int) $range->last_record > $frontier) {
                    $frontier = (int) $range->last_record;
                    $postdate = $range->last_record_postdate ?? $postdate;
                }
                DB::table('usenet_group_provider_ingested_ranges')->where('usenet_groups_id', $groupId)
                    ->where('provider', $provider)
                    ->where('first_record', $range->first_record)->delete();
            }

            return self::query()->where('usenet_groups_id', $groupId)->where('provider', $provider)->update([
                'last_record' => $frontier,
                'last_record_postdate' => $postdate,
                'last_advanced_at' => now(),
            ]);
        }, 5);
    }

    private static function dateTimeFromTimestamp(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
