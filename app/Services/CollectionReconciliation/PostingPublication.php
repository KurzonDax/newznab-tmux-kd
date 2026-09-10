<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\CompletionTally;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Support\Data\NzbCreationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Durable inventory is the journal; an atomic rename can always be replayed from it. */
class PostingPublication
{
    public static function hasGuid(string $guid): bool
    {
        return Schema::hasTable('reconciled_postings') && DB::table('reconciled_postings as p')
            ->join('releases as r', 'r.id', '=', 'p.release_id')->where('r.guid', $guid)->exists();
    }

    public static function has(int $releaseId): bool
    {
        return Schema::hasTable('reconciled_postings') && DB::table('reconciled_postings')->where('release_id', $releaseId)->exists();
    }

    public function write(Release $release, NzbService $nzbs, ?RecoveryLease $lease = null): NzbCreationResult
    {
        $temporary = null;
        try {
            $posting = DB::table('reconciled_postings')->where('release_id', $release->id)->first();
            if ($posting === null) {
                return NzbCreationResult::deferred('missing_posting_journal');
            }
            if (Schema::hasTable('reconciled_artifacts') && DB::table('reconciled_artifacts')->where('release_id', $release->id)->exists()) {
                return app(ArtifactPublication::class)->writePosting($release, null, $lease);
            }
            if ($posting->state === 'published') {
                $xml = $nzbs->readNzbContents($release->guid);
                $path = $nzbs->nzbPath($release->guid);
                if ($xml !== false && $path !== false && hash('sha256', $xml) === $posting->artifact_digest) {
                    $ids = DB::table('collections')->where('releases_id', $release->id)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                    app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids, 'Reconciled NZB cleanup retry', expectedReleaseId: (int) $release->id);

                    return NzbCreationResult::success($path, $ids);
                }

                return NzbCreationResult::deferred('published_artifact_changed');
            }
            $files = PendingInventory::decode($posting->inventory);
            $ids = DB::table('collections')->where('releases_id', $release->id)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($posting->source_digest !== null && $ids !== []
                && PendingInventory::digest((new PendingInventory)->load($ids)) !== $posting->source_digest) {
                if ($posting->review_digest !== null) {
                    throw new RuntimeException('reviewed_pending_inventory_changed');
                }
                $files = $this->refresh($posting, $ids);
                $posting = DB::table('reconciled_postings')->where('id', $posting->id)->first();
            }
            $xml = (new PostingNzb)->render($files);
            if (Schema::hasTable('reconciled_artifacts')) {
                return app(ArtifactPublication::class)->writePosting($release, $xml, $lease);
            }
            $digest = hash('sha256', $xml);
            $path = $nzbs->getNzbPath($release->guid, $nzbs->getNzbSplitLevel(), true);
            $existingPath = $nzbs->nzbPath($release->guid);
            if ($existingPath !== false) {
                $path = $existingPath;
            }
            $temporary = $path.'.reconciliation-'.bin2hex(random_bytes(8)).'.tmp';
            $encoded = gzencode($xml, 6);
            if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)
                || gzdecode((string) file_get_contents($temporary)) !== $xml) {
                throw new RuntimeException('nzb_temporary_write_failed');
            }
            $prepared = DB::transaction(function () use ($release, $posting, $digest, $lease): bool {
                $locked = Release::query()->whereKey($release->id)->lockForUpdate()->first();
                $journal = DB::table('reconciled_postings')->where('id', $posting->id)->lockForUpdate()->first();
                if ($locked === null || $journal === null || $journal->state === 'published' || $journal->digest !== $posting->digest
                    || ! $this->ownsPublication($locked, $release, $lease)) {
                    return false;
                }
                DB::table('reconciled_postings')->where('id', $posting->id)
                    ->update(['artifact_digest' => $digest, 'state' => 'prepared', 'updated_at' => now()]);

                return true;
            }, 3);
            if (! $prepared) {
                return NzbCreationResult::deferred('posting_preparation_not_owned');
            }
            $written = DB::transaction(function () use ($release, $posting, $ids, $temporary, $path, $digest, $files, $lease): bool {
                DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
                $lockedRelease = Release::query()->whereKey($release->id)->lockForUpdate()->first();
                $journal = DB::table('reconciled_postings')->where('id', $posting->id)->lockForUpdate()->first();
                if ($lockedRelease === null || $journal === null || $journal->digest !== $posting->digest
                    || ($journal->source_digest !== null && $ids !== [] && PendingInventory::digest((new PendingInventory)->load($ids)) !== $journal->source_digest)) {
                    return false;
                }
                if ((int) $lockedRelease->nzbstatus === NzbService::NZB_NONE
                    && ! NzbCreationCandidateQuery::ownedPendingBuilder((int) $release->id,
                        $release->getAttribute(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN))->exists()) {
                    return false;
                }
                if ((int) $lockedRelease->nzbstatus === NzbService::NZB_ADDED && ($lease === null || ! $lease->owns((int) $release->id))) {
                    return false;
                }
                if ($journal->independent_videos && self::hasTrustedIdentity($lockedRelease)) {
                    throw new RuntimeException('trusted_bundle_identity_conflict');
                }
                if (is_file($path)) {
                    $current = gzdecode((string) file_get_contents($path));
                    if ($current !== false && hash('sha256', $current) !== $digest && ($journal->original_nzb === null || $current !== base64_decode($journal->original_nzb, true))) {
                        throw new RuntimeException('unexpected_existing_artifact');
                    }
                }
                if (! $this->publish($temporary, $path)) {
                    throw new RuntimeException('nzb_atomic_rename_failed');
                }
                $tally = new CompletionTally;
                foreach ($files as $file) {
                    $tally->addFile(count($file->segments), $file->declaredParts, $file->total);
                }
                $decision = json_decode($journal->decision, true, flags: JSON_THROW_ON_ERROR);
                $label = ($decision['decision'] ?? $decision)['label'];
                $identity = $lockedRelease->is_trusted_name ? [] : ['name' => $label, 'searchname' => $label, 'is_trusted_name' => false, 'isrenamed' => 0];
                if ($journal->independent_videos) {
                    $identity['categories_id'] = Category::OTHER_MISC;
                }
                Release::query()->whereKey($release->id)->update([...$identity, 'nzbstatus' => NzbService::NZB_ADDED,
                    'completion' => $tally->signals()->percentage(), 'totalpart' => count($files),
                    'size' => array_sum(array_map(static fn (PostingFile $file): int => array_sum(array_column($file->segments, 'bytes')), $files))]);
                DB::table('reconciled_postings')->where('id', $journal->id)->update(['state' => 'published', 'updated_at' => now()]);
                DB::afterCommit(static fn () => Search::updateRelease((int) $release->id));

                return true;
            }, 3);
            if (! $written) {
                return NzbCreationResult::deferred('posting_inventory_changed');
            }
            $temporary = null;
            app(CollectionCleanupService::class)->deleteCollectionsAndDescendants($ids, 'Reconciled NZB cleanup', expectedReleaseId: (int) $release->id);

            return NzbCreationResult::success($path, $ids);
        } catch (\Throwable $e) {
            $this->expireUnpublished($release, $nzbs, $lease);

            return NzbCreationResult::deferred('reconciled_publication:'.$e->getMessage());
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function hasTrustedIdentity(Release $release): bool
    {
        return (bool) $release->is_trusted_name || (int) $release->predb_id > 0 || (int) $release->movieinfo_id > 0
            || (int) $release->imdbid > 0 || (int) $release->videos_id > 0 || (int) $release->tv_episodes_id > 0;
    }

    /**
     * @param  list<int>  $ids
     * @return list<PostingFile>
     */
    private function refresh(object $posting, array $ids): array
    {
        $inventory = (new PendingInventory)->load($ids);
        $digest = PendingInventory::digest($inventory);
        $deadline = DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->min('deadline');
        $remaining = $deadline === null ? 0 : strtotime($deadline) - now()->timestamp;
        if ($remaining <= 0) {
            throw new RuntimeException('posting_deadline');
        }
        $population = $inventory;
        if ($posting->original_nzb !== null) {
            $population = PendingInventory::decode($posting->inventory);
            $known = array_column($population, null, 'fileId');
            foreach ($inventory as $file) {
                $old = $known[$file->fileId] ?? null;
                if ($old === null) {
                    $population[] = $file;
                } elseif ($old->segments !== $file->segments || $old->subject !== $file->subject) {
                    throw new RuntimeException('late_inventory_changed');
                }
            }
        }
        $decision = app(PostingEvidence::class)->resolve($population, $posting->budget_id ?? 'publication:'.$posting->id, microtime(true) + min(60, $remaining));
        if (count($decision->accepted) !== count($population)) {
            throw new RuntimeException('changed_inventory_not_verified');
        }
        DB::transaction(function () use ($posting, $ids, $digest, $decision): void {
            DB::table('collections')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            if (PendingInventory::digest((new PendingInventory)->load($ids)) !== $digest) {
                throw new RuntimeException('changed_inventory');
            }
            DB::table('reconciled_postings')->where('id', $posting->id)->where('digest', $posting->digest)->update([
                'inventory' => PendingInventory::encode($decision->accepted), 'digest' => PendingInventory::digest($decision->accepted),
                'source_digest' => $digest, 'decision' => json_encode($decision, JSON_THROW_ON_ERROR),
                'independent_videos' => $decision->independentVideos(), 'updated_at' => now(),
            ]);
        }, 3);

        return $decision->accepted;
    }

    private function expireUnpublished(Release $release, NzbService $nzbs, ?RecoveryLease $lease): void
    {
        $deadline = DB::table('reconciliation_claims')->where('release_id', $release->id)->min('deadline');
        if ($deadline === null || strtotime($deadline) > now()->timestamp) {
            return;
        }
        DB::transaction(function () use ($release, $nzbs, $lease): void {
            $ids = DB::table('collections')->where('releases_id', $release->id)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $locked = Release::query()->whereKey($release->id)->lockForUpdate()->first();
            $late = DB::table('reconciled_postings')->where('release_id', $release->id)->lockForUpdate()->first();
            if ($locked !== null && $late !== null && $late->review_digest === null && $late->state !== 'published'
                && $late->previous_journal !== null && $lease !== null && $lease->owns((int) $release->id)) {
                $this->restoreLatePosting($late, $locked, $nzbs, $ids);

                return;
            }
            $pending = NzbCreationCandidateQuery::ownedPendingBuilder((int) $release->id,
                $release->getAttribute(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN))->lockForUpdate()->first();
            $journal = DB::table('reconciled_postings')->where('release_id', $release->id)->lockForUpdate()->first();
            if ($pending === null || $journal === null || $journal->review_digest !== null || $journal->original_nzb !== null || $journal->state === 'published') {
                return;
            }
            $path = $nzbs->nzbPath($release->guid);
            if ($path !== false) {
                $xml = $nzbs->readNzbContents($release->guid);
                if ($xml === false || hash('sha256', $xml) !== $journal->artifact_digest
                    || ! rename($path, $path.'.unpublished.'.$journal->artifact_digest)) {
                    return;
                }
            }
            DB::table('collections')->whereIn('id', $ids)->update(['releases_id' => null, 'filecheck' => 0]);
            DB::table('reconciliation_claims')->where('release_id', $release->id)->update([
                'release_id' => null, 'reason' => 'deadline', 'owner' => null, 'lease_until' => null,
            ]);
            DB::table('reconciled_sources')->where('posting_id', $journal->id)->delete();
            DB::table('reconciled_postings')->where('id', $journal->id)->delete();
            DB::table('releases_groups')->where('releases_id', $release->id)->delete();
            Release::query()->whereKey($release->id)->delete();
            DB::afterCommit(static fn () => Search::deleteReleases([(int) $release->id]));
            Log::info('Collection reconciliation deadline released sources', ['sources' => $ids]);
        }, 3);
    }

    private function ownsPublication(Release $locked, Release $caller, ?RecoveryLease $lease): bool
    {
        return (int) $locked->nzbstatus === NzbService::NZB_NONE
            ? NzbCreationCandidateQuery::ownedPendingBuilder((int) $caller->id, $caller->getAttribute(NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN))->exists()
            : ((int) $locked->nzbstatus === NzbService::NZB_ADDED && $lease !== null && $lease->owns((int) $caller->id));
    }

    /** @param list<int> $ids */
    private function restoreLatePosting(object $journal, Release $release, NzbService $nzbs, array $ids): void
    {
        $prior = json_decode($journal->previous_journal, true, flags: JSON_THROW_ON_ERROR);
        $xml = base64_decode($journal->original_nzb, true);
        $path = $nzbs->nzbPath($release->guid);
        $current = $nzbs->readNzbContents($release->guid);
        if ($xml === false || $path === false || $current === false || hash('sha256', $xml) !== $prior['journal']['artifact_digest']
            || ! in_array(hash('sha256', $current), [$journal->artifact_digest, $prior['journal']['artifact_digest']], true)) {
            Log::warning('Late posting abort requires unchanged artifact', ['release_id' => $release->id]);

            return;
        }
        $temporary = $path.'.restore-'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $encoded = gzencode($xml, 6);
            if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)
                || gzdecode((string) file_get_contents($temporary)) !== $xml || ! rename($temporary, $path)) {
                throw new RuntimeException('late_restore_failed');
            }
            DB::table('reconciled_postings')->where('id', $journal->id)->update([...$prior['journal'], 'previous_journal' => null, 'updated_at' => now()]);
            DB::table('reconciled_sources')->where('posting_id', $journal->id)->whereNotIn('id', $prior['source_ids'])->delete();
            DB::table('collections')->whereIn('id', $ids)->update(['releases_id' => null, 'filecheck' => 0]);
            DB::table('reconciliation_claims')->whereIn('collection_id', $ids)->update(['release_id' => null, 'owner' => null, 'lease_until' => null, 'reason' => 'deadline']);
            Log::info('Late posting restored prior artifact and released sources', ['release_id' => $release->id, 'sources' => $ids]);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function publish(string $temporary, string $path): bool
    {
        return rename($temporary, $path);
    }
}
