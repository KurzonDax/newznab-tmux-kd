<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\Releases\ForcedRootPolicy;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of an existing release's category after it was formed. Each move is a
 * compare-and-set over the category the caller read, so a concurrent rename's category is never
 * overwritten by a stale read; its {@see CategoryChangeKind} declares what else the move does.
 */
final readonly class CategoryChange
{
    public function __construct(
        private ?MediaInfoRefinementService $refinement = null,
        private PreviewGenerationPolicy $previewPolicy = new PreviewGenerationPolicy,
        private ?ReleaseSearchSyncCoordinator $searchSync = null,
        private ForcedRootPolicy $forcedRootPolicy = new ForcedRootPolicy,
    ) {}

    /** Move the release from the category the caller read to another. Returns whether the row moved. */
    public function apply(int $releaseId, int $fromCategoryId, int $toCategoryId, CategoryChangeKind $kind): bool
    {
        if ($kind->respectsForcedRoot() && ! $this->withinForcedRoot($releaseId, $toCategoryId)) {
            return false;
        }

        $links = $kind->clearsOwnedLinks() ? CategoryLinkOwnership::clearedLinks($fromCategoryId, $toCategoryId) : [];
        $columns = [...$links, 'categories_id' => $toCategoryId];
        if ($kind->marksCategorized()) {
            $columns['iscategorized'] = 1;
        }
        $moved = Release::query()
            ->where('id', $releaseId)
            ->where('categories_id', $fromCategoryId)
            ->update($columns) === 1
            // MariaDB counts changed rows: a release already in the category changes none.
            || ($fromCategoryId === $toCategoryId
                && Release::query()->whereKey($releaseId)->where('categories_id', $toCategoryId)->exists());
        if (! $moved) {
            return false;
        }

        if ($kind->restoresPreviews()) {
            $this->previewPolicy->restoreOwedPreviews([$releaseId], $kind->previewRestoreSynchronizes());
        }
        if ($kind->refines()) {
            ($this->refinement ?? app(MediaInfoRefinementService::class))->refine($releaseId);
        }
        if ($kind->synchronizes()) {
            $this->synchronize($releaseId);
        }

        return true;
    }

    /**
     * Move several releases in one statement, whatever category each is in now ({@see CategoryChangeKind::AdminBulk}).
     * The caller re-syncs them. Returns how many rows moved.
     *
     * @param  list<string>  $guids
     * @param  iterable<int|string>  $releaseIds  the ids of those releases, for the preview restore
     */
    public function applyBulk(array $guids, iterable $releaseIds, int $toCategoryId): int
    {
        $kind = CategoryChangeKind::AdminBulk;
        // Each link is set from its row's old category, so the links come before categories_id:
        // MariaDB evaluates the assignments left to right.
        $links = [];
        foreach (CategoryLinkOwnership::clearedLinksByOldCategory($toCategoryId) as $link => [$ownedBefore, $cleared]) {
            $links[$link] = DB::raw("CASE WHEN {$ownedBefore} THEN ".($cleared === null ? 'NULL' : $cleared)." ELSE {$link} END");
        }
        $moved = Release::query()
            ->whereIn('guid', $guids)
            ->update([...$links, 'categories_id' => $toCategoryId, 'iscategorized' => 1]);

        if ($moved > 0) {
            $this->previewPolicy->restoreOwedPreviews($releaseIds, $kind->previewRestoreSynchronizes());
        }

        return $moved;
    }

    private function withinForcedRoot(int $releaseId, int $toCategoryId): bool
    {
        $groupId = Release::query()->whereKey($releaseId)->value('groups_id');
        $forcedRoot = $groupId === null ? null : $this->forcedRootPolicy->selectForRelease((int) $groupId, $releaseId);

        return $forcedRoot === null || Category::rootCategoryFor($toCategoryId) === $forcedRoot;
    }

    private function synchronize(int $releaseId): void
    {
        if ($this->searchSync !== null) {
            $this->searchSync->request($releaseId);

            return;
        }

        ReleaseSearchIndexSync::forIds([$releaseId]);
    }
}
