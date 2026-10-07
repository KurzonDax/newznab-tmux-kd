<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Settings;
use App\Services\NNTP\NntpProviderPool;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use App\Support\Data\ProcessReleasesSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The per-request facts {@see ReleaseCompletion::stillRepairing()} needs beside a release row:
 * the repair target, the `delaytime` window and how far each secondary provider has read.
 *
 * Registered container-scoped in `AppServiceProvider` beside {@see SiteViewSettings}, so a
 * list page and the legacy chip component, which renders one release at a time without knowing
 * the page's rows, both read each value once per request. Every value resolves lazily on its
 * first read and is held for the rest of the request; a page with no incomplete release reads
 * nothing. The rule itself stays a stateless static method and keeps no cache of its own.
 */
final class ReleaseRepairingContext
{
    private ?float $target = null;

    private ?int $delayHours = null;

    /** @var array<int, int>|null group id => the earliest enabled secondary position, as a timestamp */
    private ?array $secondaryPositions = null;

    /** The completion both recovery engines select below. */
    public function target(): float
    {
        return $this->target ??= ReleaseRepairOptions::targetFromSettings();
    }

    /**
     * `delaytime` read as release formation and the reconciliation admission read it: blank or
     * missing resolves to 2, a stored 0 is honoured. Two readers disagreeing on the fallback would
     * make the chip and the formation window disagree about the same release.
     */
    public function delayHours(): int
    {
        return $this->delayHours ??= ProcessReleasesSettings::forDatabase(['delaytime' => Settings::settingValue('delaytime')])->collectionDelayTime;
    }

    /**
     * May an enabled secondary provider still be reading this post? True while the provider's
     * position in the release's group is earlier than the post date plus `delaytime` hours:
     * before that point it may not have read the post's articles, after it any late headers
     * have reached the merge. A secondary reads newest first, but its position moves only once
     * the backlog below it is complete, so the position date still bounds what it has stored.
     */
    public function secondaryStillReading(mixed $groupId, mixed $postdate): bool
    {
        if (! is_numeric($groupId) || $postdate === null || $postdate === '') {
            return false;
        }
        $position = $this->secondaryPositions()[(int) $groupId] ?? null;
        if ($position === null) {
            return false;
        }
        $limit = CarbonImmutable::parse((string) $postdate, config('app.timezone', 'UTC'))->addHours($this->delayHours());

        return $position < $limit->getTimestamp();
    }

    /**
     * One query for every enabled secondary provider's cursor at its current host, in active
     * groups only. A deactivated group's cursor is never deleted and stops moving forever, so
     * without the join every incomplete release posted just before its frozen date would read
     * "still repairing" permanently. A stalled cursor in an active group still counts: once the
     * provider resumes, its position passes the posts and the label ends.
     *
     * @return array<int, int>
     */
    private function secondaryPositions(): array
    {
        if ($this->secondaryPositions !== null) {
            return $this->secondaryPositions;
        }
        $providers = NntpProviderPool::secondaryProviders();
        if ($providers === [] || ! SchemaCapabilities::hasTable('usenet_group_provider_cursors')) {
            return $this->secondaryPositions = [];
        }
        $cursors = DB::table('usenet_group_provider_cursors as c')
            ->join('usenet_groups as g', 'g.id', '=', 'c.usenet_groups_id')
            ->where('g.active', 1)
            ->whereNotNull('c.last_record_postdate')
            ->where(static function (Builder $query) use ($providers): void {
                foreach ($providers as $provider) {
                    $query->orWhere(static function (Builder $query) use ($provider): void {
                        $query->where('c.provider', $provider->name)->where('c.provider_host', $provider->host);
                    });
                }
            })
            ->get(['c.usenet_groups_id', 'c.last_record_postdate']);
        $positions = [];
        foreach ($cursors as $cursor) {
            $group = (int) $cursor->usenet_groups_id;
            $at = CarbonImmutable::parse((string) $cursor->last_record_postdate, config('app.timezone', 'UTC'))->getTimestamp();
            $positions[$group] = isset($positions[$group]) ? min($positions[$group], $at) : $at;
        }

        return $this->secondaryPositions = $positions;
    }
}
