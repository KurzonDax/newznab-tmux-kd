<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Enums\HeaderScanDirection;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\NameFixing\PredbSearchLifecycle;
use App\Services\NNTP\NNTPService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for backfilling Usenet groups with historical articles.
 *
 * This service handles downloading older articles from Usenet groups
 * to fill in historical data. It supports:
 * - Backfilling by article count or target date
 * - Automatic group disable when backfill limit is reached
 */
final class BackfillService
{
    private const DEFAULT_ARTICLE_COUNT = 20000;

    /** `backfill_days` value that measures every group against one shared calendar date. */
    private const SHARED_STOP_DATE_MODE = 2;

    private BackfillConfig $config;

    private BinariesService $binaries;

    private NNTPService $nntp;

    public function __construct(
        ?BackfillConfig $config = null,
        ?BinariesService $binaries = null,
        ?NNTPService $nntp = null,
        private PredbSearchLifecycle $predbSearchLifecycle = new PredbSearchLifecycle,
    ) {
        $this->config = $config ?? BackfillConfig::fromSettings();
        $this->binaries = $binaries ?? new BinariesService;
        $this->nntp = $nntp ?? new NNTPService;
    }

    /**
     * Snapshot the groups that have not reached their configured backfill target.
     *
     * Quantity mode intentionally does not clamp work to the day target. A group
     * may overshoot that target by one pass, then it is excluded from the next pass.
     *
     * @return list<BackfillGroupWork>
     */
    public function eligibleGroups(): array
    {
        $backfillDays = (int) Settings::settingValue('backfill_days');
        $backfillOrder = (int) Settings::settingValue('backfill_order');
        $sharedStopDate = $this->resolveSharedStopDate($backfillDays);

        $rows = $this->groupWorkQuery()
            ->where('g.backfill', 1)
            ->whereNotNull('g.first_record')
            ->whereNotNull('g.first_record_postdate')
            ->whereColumn('g.first_record', '>', 'server_group.first_record')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $target = $this->targetForGroup($backfillDays, (int) $row->backfill_target, $sharedStopDate);

            // An unusable shared stop date leaves nothing eligible; see resolveSharedStopDate().
            if ($target === null) {
                continue;
            }

            $firstRecordPostdate = Carbon::parse((string) $row->first_record_postdate);

            if ($firstRecordPostdate->lessThanOrEqualTo($target)) {
                continue;
            }

            $groups[] = $this->workFromRow((array) $row, $target);
        }

        usort($groups, static function (BackfillGroupWork $left, BackfillGroupWork $right) use ($backfillOrder): int {
            $comparison = match ($backfillOrder) {
                1 => strcmp($right->firstRecordPostdate, $left->firstRecordPostdate),
                2 => strcmp($left->firstRecordPostdate, $right->firstRecordPostdate),
                3 => strcmp($left->name, $right->name),
                4 => strcmp($right->name, $left->name),
                5 => $right->serverLastRecord <=> $left->serverLastRecord,
                default => $left->serverLastRecord <=> $right->serverLastRecord,
            };

            return $comparison !== 0 ? $comparison : strcmp($left->name, $right->name);
        });

        return $groups;
    }

    /**
     * Resolve display status for a scheduled group without re-evaluating pass eligibility.
     */
    public function groupWork(string $groupName): ?BackfillGroupWork
    {
        $row = $this->groupWorkQuery()
            ->where('g.name', $groupName)
            ->whereNotNull('g.first_record')
            ->whereNotNull('g.first_record_postdate')
            ->first();

        if ($row === null) {
            return null;
        }

        $backfillDays = (int) Settings::settingValue('backfill_days');
        $target = $this->targetForGroup(
            $backfillDays,
            (int) $row->backfill_target,
            $this->resolveSharedStopDate($backfillDays),
        );

        if ($target === null) {
            return null;
        }

        return $this->workFromRow((array) $row, $target);
    }

    private function groupWorkQuery(): Builder
    {
        $serverGroups = DB::table('short_groups')
            ->selectRaw('name, MAX(first_record) AS first_record, MAX(last_record) AS last_record')
            ->groupBy('name');

        return DB::table('usenet_groups as g')
            ->joinSub($serverGroups, 'server_group', function ($join): void {
                $join->on('g.name', '=', 'server_group.name');
            })
            ->select([
                'g.name',
                'g.first_record',
                'g.first_record_postdate',
                'g.backfill_target',
                'server_group.first_record as server_first_record',
                'server_group.last_record as server_last_record',
            ]);
    }

    /**
     * Resolve the date a single group is measured against.
     *
     * Null only ever means "shared-stop-date mode with an unusable setting", which is the
     * caller's cue to treat the group as ineligible rather than to pick another target.
     */
    private function targetForGroup(int $backfillDays, int $backfillTarget, ?Carbon $sharedStopDate): ?Carbon
    {
        return $backfillDays === self::SHARED_STOP_DATE_MODE
            ? $sharedStopDate?->copy()
            : now()->subDays($backfillTarget);
    }

    /**
     * Resolve the stop date every group shares, when this pass has a usable one at all.
     *
     * Null therefore covers both "this mode measures against per-group day counts instead"
     * and "mode 2, but the configured date is unusable"; targetForGroup() is what tells the
     * two apart, and only the second warns.
     *
     * A missing or blank row still resolves to the coded default, through the same helper the
     * rest of the settings use, so a stored 0 stays a value somebody typed rather than a
     * falsy stand-in for an empty row. A stored value that is not a real `YYYY-MM-DD` date is
     * a configuration error rather than something to reinterpret:
     * the coded default is the earliest stop date there is, so quietly substituting it for a
     * typo would schedule maximal backfill instead of stopping. Parse failure therefore fails
     * closed -- no group is eligible -- and every pass warns until the setting is corrected.
     *
     * A valid date resolves to the start of that day, so the cutoff is deterministic rather
     * than "that date at whatever time the pass happens to run", and matches what the admin
     * form displays.
     */
    private function resolveSharedStopDate(int $backfillDays): ?Carbon
    {
        if ($backfillDays !== self::SHARED_STOP_DATE_MODE) {
            return null;
        }

        $stored = (string) Settings::settingValueOr('safebackfilldate', $this->config->safeBackFillDate);
        $parsed = null;

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $stored);
        } catch (InvalidFormatException) {
            // Reported as a configuration error below.
        }

        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $stored) {
            $message = sprintf(
                'Backfill setting safebackfilldate is not a valid YYYY-MM-DD date ("%s"); '
                .'no group is eligible for backfill until it is corrected.',
                $stored,
            );

            Log::warning($message);
            $this->log($message, 'warning');

            return null;
        }

        return $parsed->startOfDay();
    }

    /**
     * @param  array{name: mixed, first_record: mixed, first_record_postdate: mixed, server_first_record: mixed, server_last_record: mixed}  $row
     */
    private function workFromRow(array $row, Carbon $target): BackfillGroupWork
    {
        return new BackfillGroupWork(
            name: (string) $row['name'],
            remaining: (int) $row['first_record'] - (int) $row['server_first_record'],
            targetDate: $target->toDateString(),
            firstRecordPostdate: Carbon::parse((string) $row['first_record_postdate'])->toDateTimeString(),
            serverLastRecord: (int) $row['server_last_record'],
        );
    }

    /**
     * Backfill all groups or a specific group.
     *
     * @param  string  $groupName  Optional specific group to backfill
     * @param  int|string|null  $articles  Number of articles to backfill, or empty for date-based
     * @param  string  $type  Backfill type filter
     *
     * @throws \Throwable
     */
    public function backfillAllGroups(string $groupName = '', int|string|null $articles = '', string $type = ''): void
    {
        $groups = $this->getGroupsToBackfill($groupName, $type);

        if ($groups === []) {
            $this->log('No groups specified. Ensure groups are added to database for updating.', 'warning');

            return;
        }

        $groupCount = \count($groups);
        $this->logBackfillStart($groupCount);

        $startTime = now();

        foreach ($groups as $index => $group) {
            $this->logGroupProgress($groupName, $index + 1, $groupCount); // @phpstan-ignore binaryOp.invalid
            $this->backfillGroup($group->toArray(), $groupCount - $index - 1, $articles); // @phpstan-ignore binaryOp.invalid
        }

        $this->logBackfillComplete($startTime);
    }

    /**
     * Backfill a single group.
     *
     * @param  array<string, mixed>  $groupArr  Group data array
     * @param  int  $remainingGroups  Number of groups remaining after this one
     * @param  int|string|null  $articles  Number of articles to backfill, or empty for date-based
     *
     * @throws \Throwable
     */
    public function backfillGroup(array $groupArr, int $remainingGroups, int|string|null $articles = ''): void
    {
        $articles = $this->normalizeArticleCount($articles);
        $startTime = now();
        $this->binaries->logIndexerStart();

        $shortGroupName = $this->getShortGroupName($groupArr['name']);

        $this->validateGroupState($groupArr, $shortGroupName);

        $serverData = $this->selectNntpGroup($groupArr['name']);

        $this->log("Processing {$shortGroupName}", 'primary');

        $targetPost = $this->calculateTargetPost($groupArr, $articles, $serverData);

        if (! $this->validateTargetPost($groupArr, $targetPost, $serverData, $shortGroupName)) {
            $this->settleBackfillAtLimit($groupArr, (int) $serverData['first']);

            return;
        }

        $this->logGroupInfo($groupArr, $serverData, $targetPost, $shortGroupName);

        $this->processBackfillChunks($groupArr, $targetPost, $remainingGroups, $shortGroupName, (int) $serverData['first']);

        $this->logGroupComplete($shortGroupName, $startTime);
    }

    /**
     * Get groups to backfill based on criteria.
     *
     * @return array<string, mixed>
     */
    private function getGroupsToBackfill(string $groupName, string $type): array
    {
        if ($groupName !== '') {
            $group = UsenetGroup::getByName($groupName);

            return $group ? [$group] : [];
        }

        return UsenetGroup::getActiveBackfill($type)->all();
    }

    /**
     * Normalize the requested article count.
     *
     * An empty string is the one way to ask for date-based backfill; every other input is an
     * article-mode request, and a request for no articles is misconfiguration rather than an
     * instruction to do nothing. A stored `backfill_qty` of 0, a missing settings row (cast to
     * 0 by the tmux runner, forwarded raw as null by the console command), a negative value and
     * a non-numeric value therefore all resolve to the same coded default. Without this, a
     * zero-article target lands on the group's own first record, which the achievability check
     * reads as "history exhausted" -- and with auto-disable on that switches backfill off for
     * every group the pass touches. Pausing backfill is what the tmux switches are for.
     */
    private function normalizeArticleCount(int|string|null $articles): int|string
    {
        if ($articles === '') {
            return '';
        }

        if ($articles === null || ! is_numeric($articles) || (int) $articles <= 0) {
            return self::DEFAULT_ARTICLE_COUNT;
        }

        return $articles;
    }

    /**
     * Get shortened group name for display.
     */
    private function getShortGroupName(string $groupName): string
    {
        return str_replace('alt.binaries', 'a.b', $groupName);
    }

    /**
     * Validate that group is in a valid state for backfilling.
     *
     * @param  array<string, mixed>  $groupArr
     */
    private function validateGroupState(array $groupArr, string $shortGroupName): void
    {
        if ($groupArr['first_record'] <= 0) {
            throw new RuntimeException(
                "You need to run update_binaries on {$shortGroupName}. Otherwise the group is dead, you must disable it."
            );
        }
    }

    /**
     * Select NNTP group and return server data.
     *
     * @return array<string, mixed>
     */
    private function selectNntpGroup(string $groupName): array
    {
        $data = $this->nntp->selectGroup($groupName);

        if (NNTPService::isError($data)) {
            $data = $this->nntp->dataError($this->nntp, $groupName);
            if (NNTPService::isError($data)) {
                throw new RuntimeException("Unable to select Usenet group {$groupName}.");
            }
        }

        return $data;
    }

    /**
     * Calculate target post number based on articles count or date.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function calculateTargetPost(array $groupArr, int|string $articles, array $serverData): int
    {
        $isArticleBased = $articles !== '';

        $targetPost = $isArticleBased
            ? (int) round($groupArr['first_record'] - (int) $articles)
            : (int) $this->binaries->daytopost($groupArr['backfill_target'], $serverData);

        // Ensure target is not below server's oldest article
        return max($targetPost, (int) $serverData['first']);
    }

    /**
     * Validate that target post is achievable.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function validateTargetPost(array $groupArr, int $targetPost, array $serverData, string $shortGroupName): bool
    {
        if ($targetPost >= $groupArr['first_record'] || $groupArr['first_record'] <= $serverData['first']) {
            $message = "We have hit the maximum we can backfill for {$shortGroupName}";
            $message .= $this->config->disableBackfillGroup
                ? ', disabling backfill on it.'
                : ', skipping it, consider disabling backfill on it.';

            if ($this->config->disableBackfillGroup) {
                UsenetGroup::updateGroupStatus($groupArr['id'], 'backfill', 0);
            }

            $this->log($message, 'notice');

            return false;
        }

        return true;
    }

    /**
     * Process backfill in chunks.
     *
     * @param  array<string, mixed>  $groupArr
     */
    private function processBackfillChunks(array $groupArr, int $targetPost, int $remainingGroups, string $shortGroupName, int $serverFirst): void
    {
        $messageBuffer = $this->binaries->getMessageBuffer();
        $last = $groupArr['first_record'] - 1;
        $first = max($last - $messageBuffer + 1, $targetPost);

        while (true) {
            $this->logChunkProgress($first, $last, $shortGroupName, $remainingGroups, $targetPost);

            flush();
            $scanResult = $this->binaries->scan($groupArr, $first, $last, HeaderScanDirection::Tail, $this->config->safePartRepair);

            if ($this->binaries->lastScanWasRejected()) {
                break;
            }

            $this->updateGroupRecord($groupArr, $first, $scanResult);
            $firstArticleDate = $scanResult['firstArticleDate'] ?? null;
            $lastArticleDate = $scanResult['lastArticleDate'] ?? null;
            if (is_string($firstArticleDate) && $firstArticleDate !== ''
                && is_string($lastArticleDate) && $lastArticleDate !== '') {
                $this->predbSearchLifecycle->rearmForBackfillWindow(
                    Carbon::parse($firstArticleDate),
                    Carbon::parse($lastArticleDate),
                );
            }

            if ($first === $targetPost) {
                $this->settleBackfillAtLimit($groupArr, $serverFirst);
                break;
            }

            // Move to next chunk
            $last = $first - 1;
            $first = max($last - $messageBuffer + 1, $targetPost);
        }
    }

    /** @param array<string, mixed> $group */
    private function settleBackfillAtLimit(array $group, int $serverFirst): void
    {
        $backfillDays = (int) Settings::settingValue('backfill_days');
        $stopDate = $this->targetForGroup(
            $backfillDays, (int) $group['backfill_target'], $this->resolveSharedStopDate($backfillDays),
        );
        UsenetGroup::query()->whereKey((int) $group['id'])
            ->where(function ($query) use ($serverFirst, $stopDate): void {
                $query->where('first_record', '<=', $serverFirst);
                if ($stopDate !== null) {
                    $query->orWhere('first_record_postdate', '<=', $stopDate);
                }
            })->update(['backfill_settled_at' => now()]);
    }

    /**
     * Update group record with new first_record and postdate.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $scanResult
     */
    private function updateGroupRecord(array $groupArr, int $first, array $scanResult): void
    {
        $newDate = isset($scanResult['firstArticleDate'])
            ? strtotime($scanResult['firstArticleDate'])
            : $this->binaries->postdate($first, $this->nntp->selectGroup($groupArr['name']));

        UsenetGroup::recordBackfillProgress((int) $groupArr['id'], $first, (int) $newDate);
    }

    /**
     * Log message with appropriate styling.
     */
    private function log(string $message, string $type = 'primary'): void
    {
        if (! $this->config->echoCli) {
            return;
        }

        match ($type) {
            'header' => cli()->header($message),
            'warning' => cli()->warning($message),
            'error' => cli()->error($message),
            'notice' => cli()->notice($message),
            default => cli()->primary($message),
        };
    }

    /**
     * Log backfill start information.
     */
    private function logBackfillStart(int $groupCount): void
    {
        $compressionStatus = $this->config->compressedHeaders ? 'Yes' : 'No';
        $this->log("Backfilling: {$groupCount} group(s) - Using compression? {$compressionStatus}", 'header');
    }

    /**
     * Log group progress.
     */
    private function logGroupProgress(string $groupName, int $current, int $total): void
    {
        if ($groupName === '') {
            $this->log("Starting group {$current} of {$total}", 'header');
        }
    }

    /**
     * Log backfill completion.
     */
    private function logBackfillComplete(Carbon $startTime): void
    {
        $duration = now()->diffInSeconds($startTime, true);
        $this->log("Backfilling completed in {$duration} seconds.");
    }

    /**
     * Log group info before processing.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function logGroupInfo(array $groupArr, array $serverData, int $targetPost, string $shortGroupName): void
    {
        $this->log(sprintf(
            "Group %s's oldest article is %s, newest is %s. Our target article is %s. Our oldest article is article %s.",
            $shortGroupName,
            number_format((float) $serverData['first']),
            number_format((float) $serverData['last']),
            number_format($targetPost),
            number_format((float) $groupArr['first_record'])
        ));
    }

    /**
     * Log chunk progress.
     */
    private function logChunkProgress(int $first, int $last, string $shortGroupName, int $remainingGroups, int $targetPost): void
    {
        $this->log(sprintf(
            'Getting %s articles from %s, %d group(s) left. (%s articles in queue)',
            number_format($last - $first + 1),
            $shortGroupName,
            $remainingGroups,
            number_format($first - $targetPost)
        ), 'header');
    }

    /**
     * Log group completion.
     */
    private function logGroupComplete(string $shortGroupName, Carbon $startTime): void
    {
        $duration = number_format(now()->timestamp - $startTime->timestamp, 2);
        $this->log(PHP_EOL."Group {$shortGroupName} processed in {$duration} seconds.");
    }
}
