<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('releases:requeue-missing-video-previews
    {--dry-run : Report the matching releases without changing them (default)}
    {--apply : Return matching releases to the pending state}
    {--mp4-tail : Restrict to the bare MP4/M4V/MOV tail-recovery backlog}
    {--limit= : Maximum number of releases to re-queue}
    {--category= : Restrict to one leaf category ID}')]
#[Description('Re-queue releases affected by missing media previews')]
class RequeueMissingVideoPreviews extends Command
{
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if ($this->option('mp4-tail')) {
            return $this->handleMp4TailBacklog();
        }

        $pendingPasswordStatus = PasswordInspectionMode::pendingReleaseStatus();

        if (! $this->option('apply')) {
            $count = $this->candidates()->count();
            $strandedCount = $this->stranded($pendingPasswordStatus)->count();

            $this->info("Dry run: {$count} releases would be re-queued.");
            $this->info("Dry run: {$strandedCount} stranded releases would be repaired.");

            return self::SUCCESS;
        }

        $updated = $this->candidates()->update(ReleaseClaimant::rependValues());

        $repaired = $this->stranded($pendingPasswordStatus)->update(ReleaseClaimant::rependValues());

        $this->info("Re-queued {$updated} releases.");
        $this->info("Repaired {$repaired} stranded releases.");

        return self::SUCCESS;
    }

    private function handleMp4TailBacklog(): int
    {
        $candidateIds = $this->mp4TailCandidateIds();
        $count = count($candidateIds);
        $noun = $count === 1 ? 'release' : 'releases';

        if (! $this->option('apply')) {
            $this->info("Dry run: {$count} MP4 tail {$noun} would be re-queued.");

            return self::SUCCESS;
        }

        $updated = $candidateIds === []
            ? 0
            : Release::query()->whereIn('id', $candidateIds)->update(ReleaseClaimant::rependValues());
        $noun = $updated === 1 ? 'release' : 'releases';
        $this->info("Re-queued {$updated} MP4 tail {$noun}.");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function mp4TailCandidateIds(): array
    {
        $minimumBytes = AdditionalCandidateQuery::minSizeBytes();
        $maximumBytes = AdditionalCandidateQuery::maxSizeBytes();
        $enabledCategoryIds = Category::query()
            ->whereHas('parent', static fn (Builder $query): Builder => $query->where('generate_previews', true))
            ->pluck('id');
        $query = Release::query()
            ->where('haspreview', 0)
            ->where('passwordstatus', 0)
            ->where('rarinnerfilecount', 0)
            ->where('nzbstatus', 1)
            ->whereIn('categories_id', $enabledCategoryIds)
            ->where(static function (Builder $query): void {
                $query->where('name', 'like', '%.mp4"%')
                    ->orWhere('name', 'like', '%.m4v"%')
                    ->orWhere('name', 'like', '%.mov"%');
            })
            ->orderBy('id');

        if ($minimumBytes > 0) {
            $query->where('size', '>', $minimumBytes);
        }
        if ($maximumBytes > 0) {
            $query->where('size', '<', $maximumBytes);
        }

        $categoryId = (int) $this->option('category');
        if ($categoryId > 0) {
            $query->where('categories_id', $categoryId);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Releases whose preview generation completed without producing anything
     * (haspreview 0) or was skipped by the per-root category policy (-2), and
     * that are safe to send through additional processing again. Restricted to
     * roots with Preview Generation enabled — this command is the explicit
     * backfill tool after re-enabling a root (ADR 0004).
     *
     * @return Builder<Release>
     */
    private function candidates(): Builder
    {
        return $this->candidateUniverse()
            ->whereIn('haspreview', [0, PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY])
            ->where('passwordstatus', 0)
            ->whereNotIn('categories_id', (new PreviewGenerationPolicy)->categoryIdsWithGenerationDisabled());
    }

    /**
     * Compatibility normalization for rows carrying the opposite inspection
     * sentinel. Both sentinels are now claimable, so this is no longer required
     * for eligibility; it only keeps the operator tool's historic repair count.
     *
     * @return Builder<Release>
     */
    private function stranded(int $pendingPasswordStatus): Builder
    {
        $mismatchedSentinel = $pendingPasswordStatus === -1 ? 0 : -1;

        return $this->candidateUniverse()
            ->where('haspreview', -1)
            ->where('passwordstatus', $mismatchedSentinel);
    }

    /**
     * @return Builder<Release>
     */
    private function candidateUniverse(): Builder
    {
        return Release::query()
            ->whereIn('categories_id', [...Category::MOVIES_GROUP, ...Category::TV_GROUP])
            ->where('rarinnerfilecount', 0);
    }
}
