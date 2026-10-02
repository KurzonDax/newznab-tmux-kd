<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Models\Settings;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\Data\ProcessReleasesSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whether header scanning, and each provider's header listing, is healthy.
 *
 * A provider can keep accepting connections while its XOVER listing leaves out part of every
 * post, which the `nntp` probe cannot see. Four checks run in order; the first failure is the
 * result: a. fresh releases stalled, b. a provider's scanning stopped, c. a provider's listing
 * is short, d. the listing canary has no verdict.
 */
class NntpHeaderHealthProbe implements ServiceProbeInterface
{
    /** When tmux, header scanning and release formation were last all switched on. */
    public const string GATES_OPEN_SINCE = 'nntp-headers:gates-open-since';

    /** When at least two providers were last enabled, so the canary can measure. */
    public const string CANARY_WATCH_SINCE = 'nntp-headers:canary-watch-since';

    /**
     * a. Two consecutive full hours below this many fresh releases fail. From 2026-08-19 to
     * 2026-10-02 the only hours below 30 outside the incidents were 25 and 17, and two
     * consecutive hours below 10 occurred only in the two incidents.
     */
    private const int FRESH_RELEASES_FLOOR = 10;

    /** a. Skipped unless gates have been open this long before the hour's start. */
    private const int FRESH_RELEASES_WARMUP_HOURS = 2;

    /** a. Below this delaytime, delaytime − 2 hours leaves no fresh window to count. */
    private const int MIN_DELAY_HOURS = 3;

    /**
     * b. A provider whose newest advance is older than this has stopped. 60 minutes is the
     * stall limit release formation uses for secondary cursors; provider 1 normally advances
     * within seconds.
     */
    private const int SCANNING_STALL_MINUTES = 60;

    /** c. Only a run measured within this window counts. */
    private const int NEWEST_RUN_HOURS = 2;

    /**
     * c. Fewer lines than this are too noisy to judge: samples of 25-570 lines measured from
     * 0% to 100% on healthy listings.
     */
    private const int MIN_SAMPLED = 1000;

    /** c, d. A pair is judged on at least this many qualifying groups. */
    private const int MIN_GROUPS = 2;

    /**
     * c. Healthy listings measured 85.2% and above; the incidents measured 75.2% and below.
     */
    private const float MIN_FOUND_SHARE = 0.80;

    /** d. The canary must have reached a verdict within this window, once watched this long. */
    private const int CANARY_VERDICT_HOURS = 3;

    public function identifier(): string
    {
        return 'nntp-headers';
    }

    public function probe(): ProbeResult
    {
        $started = microtime(true);

        try {
            [$gatesOpenSince, $canaryWatchSince] = $this->refreshWatchClocks();

            foreach ([
                fn (): ?array => $this->freshReleasesStalled($gatesOpenSince),
                fn (): ?array => $this->scanningStopped($gatesOpenSince),
                fn (): ?array => $this->listingShort(),
                fn (): ?array => $this->canaryWithoutVerdict($canaryWatchSince),
            ] as $check) {
                $failure = $check();
                if ($failure !== null) {
                    return new ProbeResult(
                        ok: false,
                        responseTimeMs: $this->elapsedMs($started),
                        impact: $failure[0],
                        reason: $failure[1],
                    );
                }
            }

            return new ProbeResult(
                ok: true,
                responseTimeMs: $this->elapsedMs($started),
                impact: null,
                reason: 'Header scanning and listings healthy.',
            );
        } catch (\Throwable $e) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: $this->elapsedMs($started),
                impact: IncidentImpactEnum::Major,
                reason: 'NNTP headers probe failed: '.Str::limit($e->getMessage(), 120),
            );
        }
    }

    /**
     * Two checks must not fire just because something was recently switched on, so each keeps
     * the time it was last switched on. A missing key only delays the checks reading it.
     *
     * @return array{?int, ?int} Unix times, or null while switched off.
     */
    private function refreshWatchClocks(): array
    {
        $gatesOpen = filter_var(Settings::settingValue('running'), FILTER_VALIDATE_BOOL)
            && (string) Settings::settingValue('binaries') === '1'
            && (string) Settings::settingValue('releases') !== '0';

        return [
            $this->watch(self::GATES_OPEN_SINCE, $gatesOpen),
            $this->watch(self::CANARY_WATCH_SINCE, \count($this->enabledProviders()) >= 2),
        ];
    }

    private function watch(string $key, bool $on): ?int
    {
        if (! $on) {
            Cache::forget($key);

            return null;
        }

        $since = Cache::get($key);
        if (! is_numeric($since)) {
            $since = now()->timestamp;
            Cache::forever($key, $since);
        }

        return (int) $since;
    }

    /** @return array{IncidentImpactEnum, string}|null */
    private function freshReleasesStalled(?int $gatesOpenSince): ?array
    {
        $hour = now()->startOfHour();
        if ($gatesOpenSince === null || $gatesOpenSince > $hour->copy()->subHours(self::FRESH_RELEASES_WARMUP_HOURS)->timestamp) {
            return null;
        }

        $delayHours = ProcessReleasesSettings::forDatabase(['delaytime' => Settings::settingValue('delaytime')])->collectionDelayTime;
        if ($delayHours < self::MIN_DELAY_HOURS) {
            return null;
        }

        // Formed before the release delay could have promoted it: (delaytime − 2) hours.
        $minutes = ($delayHours - 2) * 60;
        $limit = DB::getDriverName() === 'sqlite'
            ? "datetime(postdate, ? || ' minutes')"
            : 'DATE_ADD(postdate, INTERVAL ? MINUTE)';
        $count = static fn (CarbonInterface $from, CarbonInterface $to): int => DB::table('releases')
            ->where('adddate', '>=', $from->format('Y-m-d H:i:s'))
            ->where('adddate', '<', $to->format('Y-m-d H:i:s'))
            ->whereRaw("adddate < {$limit}", [$minutes])
            ->count();

        $earlier = $count($hour->copy()->subHours(2), $hour->copy()->subHour());
        $later = $count($hour->copy()->subHour(), $hour);
        if ($earlier >= self::FRESH_RELEASES_FLOOR || $later >= self::FRESH_RELEASES_FLOOR) {
            return null;
        }

        return [IncidentImpactEnum::Major, sprintf(
            'Fresh releases stalled: %d and %d in the last two full hours (alert below %d).',
            $earlier, $later, self::FRESH_RELEASES_FLOOR,
        )];
    }

    /** @return array{IncidentImpactEnum, string}|null */
    private function scanningStopped(?int $gatesOpenSince): ?array
    {
        if ($gatesOpenSince === null || $gatesOpenSince > now()->subMinutes(self::SCANNING_STALL_MINUTES)->timestamp) {
            return null;
        }

        // Backfill and admin edits also write last_updated, so backfilling groups are left out.
        $primaryNewest = DB::table('usenet_groups')->where('active', 1)->where('backfill', 0)->max('last_updated');
        $failure = $this->stalled(NntpProviderPool::primaryProvider(), $primaryNewest);
        if ($failure !== null) {
            return $failure;
        }

        foreach ($this->enabledProviders() as $provider) {
            if ($provider->isPrimary()) {
                continue;
            }
            $newest = DB::table('usenet_group_provider_cursors as c')
                ->join('usenet_groups as g', 'g.id', '=', 'c.usenet_groups_id')
                ->where('c.provider', $provider->name)
                ->where('c.provider_host', $provider->host)
                ->where('g.active', 1)
                ->max('c.last_advanced_at');
            $failure = $this->stalled($provider, $newest);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /** @return array{IncidentImpactEnum, string}|null */
    private function stalled(NntpProvider $provider, mixed $newest): ?array
    {
        if ($newest === null || $newest === '') {
            return null;
        }

        $at = Carbon::parse((string) $newest, config('app.timezone'));
        if ($at->greaterThanOrEqualTo(now()->subMinutes(self::SCANNING_STALL_MINUTES))) {
            return null;
        }

        return [IncidentImpactEnum::Major, sprintf(
            '%s header scanning has not advanced any group for %d minutes.',
            $provider->name, (int) $at->diffInMinutes(now(), true),
        )];
    }

    /** @return array{IncidentImpactEnum, string}|null */
    private function listingShort(): ?array
    {
        $newest = DB::table('nntp_listing_coverage')->max('measured_at');
        if ($newest === null || Carbon::parse((string) $newest, config('app.timezone'))->lessThan(now()->subHours(self::NEWEST_RUN_HOURS))) {
            return null;
        }

        $shares = [];
        foreach (DB::table('nntp_listing_coverage')->where('measured_at', $newest)->where('sampled', '>=', self::MIN_SAMPLED)
            ->get(['sample_provider', 'reference_provider', 'sampled', 'found']) as $row) {
            $shares[$row->sample_provider."\0".$row->reference_provider][] = (int) $row->found / (int) $row->sampled;
        }

        foreach ($shares as $pair => $values) {
            if (\count($values) < self::MIN_GROUPS) {
                continue;
            }
            $median = $this->median($values);
            if ($median < self::MIN_FOUND_SHARE) {
                [$sample, $reference] = explode("\0", $pair);

                return [IncidentImpactEnum::Major, sprintf(
                    '%s lists only %s%% of what %s lists (median of %d groups).',
                    $reference, number_format($median * 100, 1), $sample, \count($values),
                )];
            }
        }

        return null;
    }

    /** @return array{IncidentImpactEnum, string}|null */
    private function canaryWithoutVerdict(?int $canaryWatchSince): ?array
    {
        if ($canaryWatchSince === null || $canaryWatchSince > now()->subHours(self::CANARY_VERDICT_HOURS)->timestamp) {
            return null;
        }

        $pairs = [];
        $primary = NntpProviderPool::primaryProvider();
        foreach ($this->enabledProviders() as $provider) {
            if (! $provider->isPrimary()) {
                $pairs[] = $primary->name."\0".$provider->name;
                $pairs[] = $provider->name."\0".$primary->name;
            }
        }

        $qualifying = [];
        foreach (DB::table('nntp_listing_coverage')->where('sampled', '>=', self::MIN_SAMPLED)
            ->groupBy('measured_at', 'sample_provider', 'reference_provider')
            ->get(['measured_at', 'sample_provider', 'reference_provider', DB::raw('COUNT(*) as groups_measured')]) as $row) {
            if ((int) $row->groups_measured >= self::MIN_GROUPS) {
                $qualifying[(string) $row->measured_at][$row->sample_provider."\0".$row->reference_provider] = true;
            }
        }

        $newestConclusive = null;
        foreach ($qualifying as $measuredAt => $runPairs) {
            if ($pairs !== [] && array_diff($pairs, array_keys($runPairs)) === []
                && ($newestConclusive === null || strcmp($measuredAt, $newestConclusive) > 0)) {
                $newestConclusive = $measuredAt;
            }
        }

        $since = now()->subHours(self::CANARY_VERDICT_HOURS);
        if ($newestConclusive !== null && Carbon::parse($newestConclusive, config('app.timezone'))->greaterThanOrEqualTo($since)) {
            return null;
        }

        return [IncidentImpactEnum::Minor, sprintf(
            'Listing canary has had no conclusive run since %s.',
            $newestConclusive ?? 'it started',
        )];
    }

    /** @return list<NntpProvider> */
    private function enabledProviders(): array
    {
        return array_values(array_filter(NntpProviderPool::configuredProviders(), static fn (NntpProvider $p): bool => $p->enabled));
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
