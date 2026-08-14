<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('releases:requeue-missing-video-previews
    {--dry-run : Report the matching releases without changing them (default)}
    {--apply : Return matching releases to the pending state}')]
#[Description('Re-queue non-RAR movie and TV releases affected by missing media previews')]
class RequeueMissingVideoPreviews extends Command
{
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $pendingPasswordStatus = PasswordInspectionMode::pendingReleaseStatus();

        if (! $this->option('apply')) {
            $count = $this->candidates()->count();
            $strandedCount = $this->stranded($pendingPasswordStatus)->count();

            $this->info("Dry run: {$count} releases would be re-queued.");
            $this->info("Dry run: {$strandedCount} stranded releases would be repaired.");

            return self::SUCCESS;
        }

        $updated = $this->candidates()->update([
            'haspreview' => -1,
            'passwordstatus' => $pendingPasswordStatus,
        ]);

        $repaired = $this->stranded($pendingPasswordStatus)->update([
            'passwordstatus' => $pendingPasswordStatus,
        ]);

        $this->info("Re-queued {$updated} releases.");
        $this->info("Repaired {$repaired} stranded releases.");

        return self::SUCCESS;
    }

    /**
     * Releases whose preview generation completed without producing anything
     * and that are safe to send through additional processing again.
     *
     * @return Builder<Release>
     */
    private function candidates(): Builder
    {
        return $this->candidateUniverse()
            ->where('haspreview', 0)
            ->where('passwordstatus', 0);
    }

    /**
     * Releases already flagged pending for preview work but carrying the
     * password sentinel of the opposite inspection mode, which the additional
     * processing candidate selection never matches. Prior runs of this command
     * hardcoded -1 and stranded rows this way on inspection-off sites.
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
