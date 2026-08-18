<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsenetGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Repairs usenet_groups rows whose last_record was overwritten with a non-numeric
 * header field (issue #116). MariaDB runs non-strict here, so a subject string
 * written to last_record was silently coerced to 0, a small integer, or the
 * unsigned bigint ceiling, leaving the group unable to advance.
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
                            {--execute : Apply the changes; without it the command only reports}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset usenet_groups.last_record for groups whose article pointer was corrupted';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $groups = $this->brokenGroups();

        if ($groups === []) {
            $this->info('No groups with a corrupted article pointer.');

            return self::SUCCESS;
        }

        $rows = [];
        $repairedGroupIds = [];

        foreach ($groups as $group) {
            $newestStored = $this->newestStoredArticle((int) $group->id);
            $resume = $newestStored !== null && (int) $newestStored->number > (int) $group->first_record;

            $rows[] = [
                $group->name,
                $this->formatArticleNumber($group->first_record),
                $this->formatArticleNumber($group->last_record),
                $resume ? $this->formatArticleNumber($newestStored->number) : '0 (rescan as new group)',
            ];
            $repairedGroupIds[] = (int) $group->id;

            if (! $execute) {
                continue;
            }

            if ($resume) {
                $this->resumeFromStoredArticles($group, $newestStored);
            } else {
                $this->reAnchorAsNewGroup($group);
            }
        }

        $this->table(['Group', 'first_record', 'last_record', 'new last_record'], $rows);

        if (! $execute) {
            $this->warn(\count($rows).' group(s) would be repaired. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        $this->info(\count($rows).' group(s) repaired.');

        if ($this->option('purge-missed-parts')) {
            $purged = DB::table('missed_parts')->whereIn('groups_id', $repairedGroupIds)->delete();
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
     * @return list<stdClass>
     */
    private function brokenGroups(): array
    {
        $query = UsenetGroup::query()
            ->select(['id', 'name', 'first_record', 'last_record'])
            ->where(function ($builder): void {
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

        return $query->get()->map(static fn ($group): stdClass => (object) $group->getAttributes())->all();
    }

    /**
     * The newest article actually stored for a group, so the scanner resumes from
     * real data instead of re-downloading everything.
     */
    private function newestStoredArticle(int $groupId): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = DB::table('parts')
            ->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
            ->join('collections', 'collections.id', '=', 'binaries.collections_id')
            ->where('collections.groups_id', '=', $groupId)
            ->orderByDesc('parts.number')
            ->limit(1)
            ->first(['parts.number as number', 'collections.date as date']);

        return $row;
    }

    private function resumeFromStoredArticles(stdClass $group, stdClass $newestStored): void
    {
        $update = [
            'last_record' => (int) $newestStored->number,
            'last_updated' => now(),
        ];

        if ($newestStored->date !== null) {
            $update['last_record_postdate'] = $newestStored->date;
        }

        UsenetGroup::query()->whereKey($group->id)->update($update);
    }

    /**
     * Nothing is stored for this group, so let the scanner treat it as new and
     * re-anchor near the server's newest article.
     */
    private function reAnchorAsNewGroup(stdClass $group): void
    {
        UsenetGroup::query()->whereKey($group->id)->update([
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => 0,
            'last_record_postdate' => null,
            'last_updated' => now(),
        ]);
    }

    private function formatArticleNumber(mixed $number): string
    {
        return number_format((float) $number, 0, '.', ',');
    }
}
