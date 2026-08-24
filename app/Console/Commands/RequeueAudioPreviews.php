<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AudioProcessing\AudioCandidateQuery;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\ReleaseImageService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

/**
 * Operator tool that pushes existing audio releases back through the dedicated
 * audio post-processing path ({@see AudioCandidateQuery}).
 *
 * Selection is the routing rule itself ({@see AudioRouting::applyRoutingPredicate()})
 * with no size floor, because the audio candidate query has none. A release is
 * re-queued by resetting `haspreview` to -1 with the pending password sentinel
 * and clearing its claim columns -- which is also what hands a previously
 * declined release back to the audio worker for one more probe.
 */
#[Signature('releases:requeue-audio-previews
    {--dry-run : Report without changing anything (default)}
    {--apply : Re-queue the matching releases}
    {--group=* : Restrict to usenet_groups.id (repeatable)}
    {--category= : Restrict to one leaf category id}
    {--include-declined : Also re-queue releases the audio path previously declined}
    {--prune-empty : Delete zero-byte files in covers/audiosample regardless of selection}
    {--limit= : Maximum releases to re-queue}')]
#[Description('Re-queue existing audio releases through the audio post-processing path')]
class RequeueAudioPreviews extends Command
{
    private const string STATE_STRANDED = 'stranded repaired';

    private const string STATE_FROM_ZERO = 're-queued from 0';

    private const string STATE_FROM_SKIPPED = 're-queued from -2';

    private const string STATE_DECLINED = 'declined re-queued';

    public function __construct(private readonly ReleaseImageService $releaseImageService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $pendingPasswordStatus = PasswordInspectionMode::pendingReleaseStatus();

        /** @var array<string, int> $counts */
        $counts = [
            self::STATE_STRANDED => 0,
            self::STATE_FROM_ZERO => 0,
            self::STATE_FROM_SKIPPED => 0,
            self::STATE_DECLINED => 0,
        ];
        /** @var array<int, array<string, int>> $perGroup */
        $perGroup = [];
        /** @var array<int, string> $batch release id => guid */
        $batch = [];

        foreach ($this->candidates($pendingPasswordStatus)->cursor() as $release) {
            $state = $this->stateOf($release);
            $counts[$state]++;
            $perGroup[(int) $release->groups_id][$state] = ($perGroup[(int) $release->groups_id][$state] ?? 0) + 1;
            $batch[(int) $release->id] = (string) $release->guid;

            if (count($batch) >= 500) {
                $this->requeue($batch, $pendingPasswordStatus, $apply);
                $batch = [];
            }
        }
        $this->requeue($batch, $pendingPasswordStatus, $apply);

        $pruned = $this->option('prune-empty') ? $this->pruneEmptyPreviews($apply) : 0;

        $this->info($apply ? 'Applied:' : 'Dry run: nothing changed. Would apply:');
        foreach ($counts as $label => $count) {
            $this->line("  {$label}: {$count}");
        }
        $this->line('  files pruned: '.$pruned);

        if (! $apply && $perGroup !== []) {
            $this->reportPerGroup($perGroup);
        }

        return self::SUCCESS;
    }

    /**
     * Audio-routed releases with a usable NZB that either finished without a
     * preview (0), were skipped by the per-root policy (-2), or are pending
     * with the wrong password sentinel (-1, stranded).
     *
     * Without --include-declined the selection is exactly what the audio worker
     * would claim ({@see AudioRouting::applyAudioPath()}). With it, the routing
     * rule alone applies, and a declined release joins whatever its preview
     * state -- including one still pending on the video path -- because
     * clearing its marker is the only way to hand it back to the audio worker.
     *
     * @return Builder<Release>
     */
    private function candidates(int $pendingPasswordStatus): Builder
    {
        $includeDeclined = (bool) $this->option('include-declined');
        $mismatchedSentinel = $pendingPasswordStatus === -1 ? 0 : -1;
        $maximumTimeoutCount = ReleaseClaimant::maxPpTimeoutCount();
        $tokenColumn = 'r.'.ReleaseClaimant::CLAIM_TOKEN_COLUMN;

        $query = Release::query()->from('releases as r')
            ->select([
                'r.id', 'r.guid', 'r.groups_id', 'r.haspreview', 'r.passwordstatus',
                $tokenColumn.' as claim_token',
            ])
            ->where('r.nzbstatus', 1)
            ->where(static function (Builder $stateQuery) use ($pendingPasswordStatus, $mismatchedSentinel, $maximumTimeoutCount, $tokenColumn, $includeDeclined): void {
                $stateQuery
                    ->whereIn('r.haspreview', [0, PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY])
                    ->orWhere(static function (Builder $stranded) use ($mismatchedSentinel): void {
                        $stranded->where('r.haspreview', -1)->where('r.passwordstatus', $mismatchedSentinel);
                    })
                    ->orWhere(static function (Builder $counterStrand) use ($pendingPasswordStatus, $maximumTimeoutCount): void {
                        $counterStrand
                            ->where('r.haspreview', -1)
                            ->where('r.passwordstatus', $pendingPasswordStatus)
                            ->where('r.pp_timeout_count', '>=', $maximumTimeoutCount);
                    });

                if ($includeDeclined) {
                    $stateQuery->orWhere($tokenColumn, AudioRouting::DECLINED_TOKEN);
                }
            })
            ->orderBy('r.id');

        $includeDeclined
            ? AudioRouting::applyRoutingPredicate($query)
            : AudioRouting::applyAudioPath($query);

        $groupIds = array_values(array_filter(array_map('intval', (array) $this->option('group'))));
        if ($groupIds !== []) {
            $query->whereIn('r.groups_id', $groupIds);
        }

        $categoryId = (int) $this->option('category');
        if ($categoryId > 0) {
            $query->where('r.categories_id', $categoryId);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    private function stateOf(Release $release): string
    {
        if (AudioRouting::isDeclined($release->getAttribute('claim_token'))) {
            return self::STATE_DECLINED;
        }

        return match ((int) $release->haspreview) {
            PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY => self::STATE_FROM_SKIPPED,
            -1 => self::STATE_STRANDED,
            default => self::STATE_FROM_ZERO,
        };
    }

    /**
     * Return one batch to the pending state, then remove its old artifacts.
     *
     * The rows are updated first so a failure leaves a release with a stale
     * preview rather than with no preview and no way of getting one.
     *
     * @param  array<int, string>  $batch  release id => guid
     */
    private function requeue(array $batch, int $pendingPasswordStatus, bool $apply): void
    {
        if (! $apply || $batch === []) {
            return;
        }

        $ids = array_keys($batch);
        ReleaseAudioTag::clearPreviews($ids);

        $pending = [
            'haspreview' => -1,
            'passwordstatus' => $pendingPasswordStatus,
            'pp_timeout_count' => 0,
        ];
        if (ReleaseClaimant::supportsClaims()) {
            $pending[ReleaseClaimant::CLAIMED_AT_COLUMN] = null;
            $pending[ReleaseClaimant::CLAIM_TOKEN_COLUMN] = null;
        }

        Release::query()->whereIn('id', $ids)->update($pending);

        foreach ($batch as $guid) {
            $this->releaseImageService->delete($guid);
        }
    }

    /**
     * Zero-byte files in the audio sample store are the residue of the retired
     * preview path writing an empty clip; nothing can serve them and they are
     * not tied to the selection above.
     */
    private function pruneEmptyPreviews(bool $apply): int
    {
        $directory = rtrim((string) config('nntmux_settings.covers_path'), '/').'/audiosample';
        if (! File::isDirectory($directory)) {
            return 0;
        }

        $pruned = 0;
        foreach (File::files($directory) as $file) {
            if ($file->getSize() !== 0) {
                continue;
            }
            $pruned++;
            if ($apply) {
                File::delete($file->getPathname());
            }
        }

        return $pruned;
    }

    /**
     * @param  array<int, array<string, int>>  $perGroup
     */
    private function reportPerGroup(array $perGroup): void
    {
        ksort($perGroup);
        $names = UsenetGroup::query()->whereIn('id', array_keys($perGroup))->pluck('name', 'id');

        $rows = [];
        foreach ($perGroup as $groupId => $states) {
            $rows[] = [
                $groupId,
                (string) ($names[$groupId] ?? '?'),
                $states[self::STATE_STRANDED] ?? 0,
                $states[self::STATE_FROM_ZERO] ?? 0,
                $states[self::STATE_FROM_SKIPPED] ?? 0,
                $states[self::STATE_DECLINED] ?? 0,
            ];
        }

        $this->newLine();
        $this->table(['Group', 'Name', 'Stranded', 'From 0', 'From -2', 'Declined'], $rows);
    }
}
