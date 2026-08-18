<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShortGroup;
use App\Models\UsenetGroup;
use App\Support\Data\GroupPointerRepairPlan;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repairs usenet_groups rows whose last_record was overwritten with a non-numeric
 * header field (issue #116). MariaDB runs non-strict here, so a subject string
 * written to last_record was silently coerced to 0, a small integer, or the
 * unsigned bigint ceiling, leaving the group unable to advance.
 *
 * Resume points come from the articles already stored for the group, but a
 * cross-posted binary carries the article number of whichever group's XOVER
 * delivered it while collections.groups_id names only one group (issue #120).
 * Every candidate is therefore checked against the group's position on the
 * server before it is written; a candidate outside that window would park the
 * group past the server's newest article, permanently at "No new articles".
 *
 * Run this only after the scan-side guards are deployed, otherwise a repaired
 * group can be corrupted again on the next pass.
 */
class RepairGroupArticlePointers extends Command
{
    /**
     * No real article number comes anywhere near this, so anything at or above it
     * is a coerced value rather than a position on the server.
     */
    private const IMPLAUSIBLE_ARTICLE_NUMBER = 1_000_000_000_000_000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'groups:repair-article-pointers
                            {--group= : Only consider this group}
                            {--include-inactive : Consider inactive groups as well}
                            {--purge-missed-parts : Also delete the missed_parts rows of every repaired group}
                            {--rescan-unverified : Re-anchor groups whose server range is unknown instead of skipping them}
                            {--execute : Apply the changes; without it the command only reports}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset usenet_groups.last_record for groups whose article pointer was corrupted, resuming only from a stored article the server still carries';

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = <<<'HELP'
        A repaired group resumes from the newest article stored for it, but only when that
        article sits inside the group's window on the server as recorded in <info>short_groups</info>
        (refreshed by <info>groups:update</info>, which you should run first). Cross-posted binaries
        carry the article number of whichever group delivered them, so an unchecked resume
        point can land past the server's newest article and leave the group stuck at
        "No new articles" forever.

        A group whose stored articles are all outside that window - or which has no stored
        articles left because CBP retention already purged them - is re-anchored as a new
        group. That is expected, not a failure: the scanner restarts near the server's
        newest article. Enable backfill for the group afterwards to recover the gap.

        A group that still has a usable stored resume point but no <info>short_groups</info> row is
        reported and skipped, because there is nothing to validate that resume point
        against. Run <info>groups:update</info> and try again, or pass <info>--rescan-unverified</info> to re-anchor
        those groups as new instead - the right call for a group the provider has dropped,
        which will never get a <info>short_groups</info> row again.
        HELP;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $groups = $this->brokenGroups();

        if ($groups->isEmpty()) {
            $this->info('No groups with a corrupted article pointer.');

            return self::SUCCESS;
        }

        $serverRanges = $this->serverRanges($groups);
        $plans = [];

        foreach ($groups as $group) {
            $plans[] = $this->planRepair($group, $serverRanges[$group->name] ?? null);
        }

        $this->table(
            ['Group', 'first_record', 'last_record', 'server range', 'new last_record'],
            array_map(fn (GroupPointerRepairPlan $plan): array => $this->reportRow($plan), $plans)
        );

        $repairable = array_values(array_filter($plans, fn (GroupPointerRepairPlan $plan): bool => $plan->isRepairable()));
        $skipped = array_filter($plans, fn (GroupPointerRepairPlan $plan): bool => ! $plan->isRepairable());

        if ($skipped !== []) {
            $this->warn(
                \count($skipped).' group(s) skipped: short_groups has no row for them, so their stored resume '.
                'point cannot be checked against the server. Run groups:update and re-run this command, or pass '.
                '--rescan-unverified to re-anchor them as new groups.'
            );
        }

        if (! $execute) {
            $this->warn(\count($repairable).' group(s) would be repaired. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        foreach ($repairable as $plan) {
            if ($plan->resumeFrom !== null) {
                $this->resumeFromStoredArticles($plan->group, $plan->resumeFrom);
            } else {
                $this->reAnchorAsNewGroup($plan->group);
            }
        }

        $this->info(\count($repairable).' group(s) repaired.');

        if ($this->option('purge-missed-parts')) {
            $repairedIds = array_map(fn (GroupPointerRepairPlan $plan): int => (int) $plan->group->id, $repairable);
            $purged = DB::table('missed_parts')->whereIn('groups_id', $repairedIds)->delete();
            $this->info(number_format($purged).' missed_parts row(s) deleted for the repaired groups.');
        }

        $this->warn(
            'Catching up is bounded by the max_headers_iteration setting per cycle, '.
            'so groups far behind the server take several passes.'
        );

        return self::SUCCESS;
    }

    /**
     * Groups whose pointer cannot be a real position on the server: either behind
     * their own oldest record, or coerced past any plausible article number.
     *
     * @return Collection<int, UsenetGroup>
     */
    private function brokenGroups(): Collection
    {
        $query = UsenetGroup::query()
            ->select(['id', 'name', 'first_record', 'last_record'])
            ->where(function (Builder $builder): void {
                $builder->whereColumn('last_record', '<', 'first_record')
                    ->orWhere('last_record', '>=', self::IMPLAUSIBLE_ARTICLE_NUMBER);
            })
            ->orderBy('name');

        if (! $this->option('include-inactive')) {
            $query->where('active', '=', 1);
        }

        if ($this->option('group') !== null) {
            $query->where('name', '=', $this->option('group'));
        }

        return $query->get();
    }

    /**
     * Where each group currently sits on the server, as of the last groups:update.
     *
     * @param  Collection<int, UsenetGroup>  $groups
     * @return array<string, array{first: int, last: int}>
     */
    private function serverRanges(Collection $groups): array
    {
        $ranges = [];

        $rows = ShortGroup::query()
            ->whereIn('name', $groups->pluck('name')->all())
            ->get(['name', 'first_record', 'last_record']);

        foreach ($rows as $row) {
            $ranges[(string) $row->name] = ['first' => (int) $row->first_record, 'last' => (int) $row->last_record];
        }

        return $ranges;
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function reportRow(GroupPointerRepairPlan $plan): array
    {
        return [
            (string) $plan->group->name,
            $this->formatArticleNumber($plan->group->first_record),
            $this->formatArticleNumber($plan->group->last_record),
            $plan->serverRangeLabel,
            $plan->outcomeLabel,
        ];
    }

    /**
     * Decide what to do with one group, and how to explain it in the report.
     *
     * @param  array{first: int, last: int}|null  $serverRange
     */
    private function planRepair(UsenetGroup $group, ?array $serverRange): GroupPointerRepairPlan
    {
        $range = $serverRange === null
            ? 'unknown'
            : $this->formatArticleNumber($serverRange['first']).' - '.$this->formatArticleNumber($serverRange['last']);

        $newestStored = $this->newestStoredArticle($group);

        // Nothing usable is stored, so the server range does not come into it: the
        // scanner will re-anchor this group from the server itself.
        if ($newestStored === null || $newestStored['number'] <= (int) $group->first_record) {
            return GroupPointerRepairPlan::reAnchor($group, $range, '0 (rescan as new group)');
        }

        if ($serverRange === null) {
            return $this->option('rescan-unverified')
                ? GroupPointerRepairPlan::reAnchor($group, $range, '0 (rescan as new group; server range unknown)')
                : GroupPointerRepairPlan::skip($group, $range, 'skipped (no short_groups row; run groups:update)');
        }

        $rejection = $this->rejectionReason($newestStored['number'], $serverRange);

        if ($rejection !== null) {
            return GroupPointerRepairPlan::reAnchor($group, $range, '0 (rescan as new group; '.$rejection.')');
        }

        return GroupPointerRepairPlan::resume($group, $newestStored, $range, $this->formatArticleNumber($newestStored['number']));
    }

    /**
     * Why a stored article cannot be where this group left off on the server.
     *
     * @param  array{first: int, last: int}  $serverRange
     */
    private function rejectionReason(int $candidate, array $serverRange): ?string
    {
        if ($candidate > $serverRange['last']) {
            return 'stored '.$this->formatArticleNumber($candidate).
                ' is above server newest '.$this->formatArticleNumber($serverRange['last']);
        }

        if ($candidate < $serverRange['first']) {
            return 'stored '.$this->formatArticleNumber($candidate).
                ' is below server oldest '.$this->formatArticleNumber($serverRange['first']);
        }

        return null;
    }

    /**
     * The newest article actually stored for a group, so the scanner resumes from
     * real data instead of re-downloading everything.
     *
     * @return array{number: int, date: string|null}|null
     */
    private function newestStoredArticle(UsenetGroup $group): ?array
    {
        $row = DB::table('parts')
            ->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
            ->join('collections', 'collections.id', '=', 'binaries.collections_id')
            ->where('collections.groups_id', '=', $group->id)
            ->orderByDesc('parts.number')
            ->limit(1)
            ->first(['parts.number as number', 'collections.date as date']);

        if ($row === null) {
            return null;
        }

        return [
            'number' => (int) $row->number,
            'date' => $row->date === null ? null : (string) $row->date,
        ];
    }

    /**
     * @param  array{number: int, date: string|null}  $newestStored
     */
    private function resumeFromStoredArticles(UsenetGroup $group, array $newestStored): void
    {
        $update = ['last_record' => $newestStored['number'], 'last_updated' => now()];

        if ($newestStored['date'] !== null) {
            $update['last_record_postdate'] = $newestStored['date'];
        }

        $group->update($update);
    }

    /**
     * Nothing usable is stored for this group, so let the scanner treat it as new
     * and re-anchor near the server's newest article.
     */
    private function reAnchorAsNewGroup(UsenetGroup $group): void
    {
        $group->update([
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => 0,
            'last_record_postdate' => null,
            'last_updated' => now(),
        ]);
    }

    /**
     * Group digits by hand: the corrupted values this command reports overflow a
     * float, so number_format() would print a rounded number back at the operator.
     */
    private function formatArticleNumber(mixed $number): string
    {
        $digits = (string) $number;

        if (! ctype_digit($digits)) {
            return $digits;
        }

        return strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
