<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImagerySkipArtifact;
use App\Models\Release;
use App\Models\ReleaseImageryDiskSkip;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Return releases whose imagery the Free-disk guard suppressed (ADR 0013) to
 * the pipeline once space on the covers volume has been reclaimed.
 *
 * The ledger is the unit of work: a requeued release's row is deleted, because
 * the re-run -- not the ledger -- decides what the release actually yields. A
 * row that yields nothing on requeue is the accepted cost of not burning
 * bandwidth during a squeeze.
 */
#[Signature('releases:requeue-imagery-disk-skips
    {--dry-run : Report the ledgered releases without changing them (default)}
    {--apply : Return the ledgered releases to the pending state}
    {--limit= : Maximum number of releases to re-queue}')]
#[Description('Re-queue releases whose imagery the free-disk guard skipped')]
class RequeueImageryDiskSkips extends Command
{
    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $releaseIds = $this->ledgeredReleaseIds();
        $count = count($releaseIds);
        $noun = $count === 1 ? 'release' : 'releases';

        if (! $this->option('apply')) {
            $this->info("Dry run: {$count} ledgered {$noun} would be re-queued.");
            $this->reportSuppressedArtifacts($releaseIds);

            return self::SUCCESS;
        }

        if ($releaseIds === []) {
            $this->info('Re-queued 0 releases.');

            return self::SUCCESS;
        }

        $requeued = Release::query()->whereIn('id', $releaseIds)->update(ReleaseClaimant::rependValues());
        ReleaseImageryDiskSkip::query()->whereIn('releases_id', $releaseIds)->delete();

        $noun = $requeued === 1 ? 'release' : 'releases';
        $this->info("Re-queued {$requeued} {$noun}.");

        return self::SUCCESS;
    }

    /**
     * Ledger rows whose release still exists. The FK cascades rows away with
     * their release, so an orphan means the join raced a deletion, not that the
     * cascade is unreliable.
     *
     * @return list<int>
     */
    private function ledgeredReleaseIds(): array
    {
        $query = ReleaseImageryDiskSkip::query()
            ->whereIn('releases_id', Release::query()->select('id'))
            ->orderBy('releases_id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $releaseIds
     */
    private function reportSuppressedArtifacts(array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }

        $counts = ReleaseImageryDiskSkip::query()
            ->whereIn('releases_id', $releaseIds)
            ->get()
            ->flatMap(static fn (ReleaseImageryDiskSkip $skip): array => $skip->artifacts())
            ->countBy(static fn (ImagerySkipArtifact $artifact): string => $artifact->value)
            ->sortKeys();

        foreach ($counts as $artifact => $count) {
            $this->info("Dry run: {$count} suppressed {$artifact} images.");
        }
    }
}
