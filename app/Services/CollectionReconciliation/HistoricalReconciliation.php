<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class HistoricalReconciliation
{
    public function __construct(private readonly PostingEvidence $evidence, private readonly NzbService $nzbs) {}

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function plan(array $ids, ?int $anchorId = null): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (count($ids) < 2 || count($ids) > 256) {
            throw new RuntimeException('Select between two and 256 source releases.');
        }
        $sources = Release::query()->whereIn('id', $ids)->orderBy('adddate')->orderBy('id')->get();
        if ($sources->count() !== count($ids)) {
            throw new RuntimeException('A source release is missing.');
        }
        $anchorId ??= (int) $sources->first()->id;
        if (! in_array($anchorId, $ids, true)) {
            throw new RuntimeException('The anchor must be one of the selected releases.');
        }
        $files = $digests = $metadata = $conflicts = [];
        foreach ($sources as $source) {
            if (self::active($source)) {
                $conflicts[] = 'active_processing:'.$source->id;
            }
            $xml = $this->nzbs->readNzbContents($source->guid);
            if ($xml === false) {
                throw new RuntimeException('Missing source NZB: '.$source->id);
            }
            $digests[$source->id] = hash('sha256', $xml);
            $metadata[$source->id] = $this->metadata($source);
            array_push($files, ...(new PostingNzb)->parse($xml, 'release:'.$source->id, DB::table('usenet_groups')->where('id', $source->groups_id)->value('name')));
            if (count($files) > 1024) {
                throw new RuntimeException('File population exceeds 1024.');
            }
        }
        $pendingIds = [];
        $pendingDigest = null;
        $pendingExcluded = [];
        $bases = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()));
        if (count($bases) === 1 && Schema::hasTable('collections')) {
            $base = $bases[0];
            $groupId = DB::table('usenet_groups')->where('name', $base->group)->value('id');
            $population = (new PopulationQuery)->readHistoricalWindow((int) $groupId, $base->total, $base->date);
            if (! $population['complete'] || $population['rows']->count() + count($ids) > PopulationQuery::LIMIT) {
                throw new RuntimeException('Combined source population exceeds 256.');
            }
            $pendingIds = $population['rows']->whereIn('filecheck', PopulationQuery::STATES)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $pending = (new PendingInventory)->load($pendingIds);
            $pendingDigest = PendingInventory::digest($pending);
            $known = array_fill_keys(array_map(static fn (PostingFile $file): string => $file->firstArticle(), $files), true);
            $overlapping = [];
            foreach ($pending as $file) {
                if (isset($known[$file->firstArticle()])) {
                    $overlapping[$file->sourceId] = true;
                }
            }
            foreach ($pending as $file) {
                if (isset($overlapping[$file->sourceId])) {
                    $pendingExcluded[$file->sourceId] = 'contains_articles_already_in_source_nzbs';

                    continue;
                }
                $files[] = $file;
            }
            if (count($files) > 1024) {
                throw new RuntimeException('Combined file population exceeds 1024.');
            }
        }
        $decision = $this->evidence->resolve($files, hash('sha256', 'historical:'.implode(',', $ids)), microtime(true) + 60, false);
        $titles = $movies = $episodes = [];
        foreach ($sources as $source) {
            if ($source->is_trusted_name) {
                $titles[] = $source->searchname;
            }
            if ((int) $source->movieinfo_id > 0 || (int) $source->imdbid > 0) {
                $movies[] = (string) $source->movieinfo_id.':'.(string) $source->imdbid;
            }
            if ((int) $source->videos_id > 0 || (int) $source->tv_episodes_id > 0) {
                $episodes[] = (string) $source->videos_id.':'.(string) $source->tv_episodes_id;
            }
        }
        if (count(array_unique($titles)) > 1 || count(array_unique($movies)) > 1 || count(array_unique($episodes)) > 1
            || ($decision->independentVideos() && ($titles !== [] || $movies !== [] || $episodes !== []))) {
            $conflicts[] = 'trusted_title_or_metadata_conflict';
        }
        if (! in_array('release:'.$anchorId, $decision->sources(), true)) {
            $conflicts[] = 'anchor_not_verified';
        }
        $plan = ['version' => PostingEvidence::VERSION, 'sources' => $ids, 'anchor' => $anchorId,
            'nzb_digests' => $digests, 'metadata' => $metadata, 'pending_ids' => $pendingIds, 'pending_digest' => $pendingDigest, 'pending_excluded' => $pendingExcluded, 'decision' => json_decode(json_encode($decision, JSON_THROW_ON_ERROR), true),
            'completion' => $decision->completion(), 'conflicts' => $conflicts, 'applicable' => $conflicts === [] && $decision->accepted !== []];
        $plan['digest'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** @param list<int> $ids */
    public function apply(array $ids, ?int $anchorId, string $digest): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new RuntimeException('Apply requires the reviewed plan digest.');
        }
        $existing = DB::table('reconciled_postings')->where('review_digest', $digest)->first();
        if ($existing !== null) {
            $acceptedPlan = json_decode($existing->decision, true, flags: JSON_THROW_ON_ERROR);
            $selected = array_values(array_unique($ids));
            sort($selected);
            if ($selected !== $acceptedPlan['sources'] || ($anchorId !== null && $anchorId !== (int) $existing->release_id)) {
                throw new RuntimeException('Digest belongs to another selection or anchor.');
            }
            $leases = [];
            try {
                $release = Release::query()->findOrFail($existing->release_id);
                if ($existing->state !== 'published') {
                    DB::transaction(function () use ($acceptedPlan, $existing, &$leases): void {
                        $sources = Release::query()->whereIn('id', $acceptedPlan['sources'])->orderBy('id')->lockForUpdate()->get();
                        if ($sources->count() !== count($acceptedPlan['sources'])) {
                            throw new RuntimeException('Source disappeared before resume.');
                        }
                        foreach ($sources as $source) {
                            $xml = $this->nzbs->readNzbContents($source->guid);
                            $currentDigest = $xml === false ? '' : hash('sha256', $xml);
                            $artifactMatches = $currentDigest === $acceptedPlan['nzb_digests'][$source->id]
                                || ((int) $source->id === (int) $existing->release_id && $currentDigest === $existing->artifact_digest);
                            if (self::active($source) || ! $artifactMatches || $this->metadata($source) !== $acceptedPlan['metadata'][$source->id]) {
                                throw new RuntimeException('Source changed since interrupted apply.');
                            }
                            $lease = RecoveryLease::acquire($source);
                            if ($lease === null) {
                                throw new RuntimeException('Source is actively processed.');
                            }
                            $leases[(int) $source->id] = $lease;
                        }
                    }, 3);
                }
                $result = app(PostingPublication::class)->write($release, $this->nzbs, $leases[(int) $release->id] ?? null);
                if (! $result->success) {
                    throw new RuntimeException($result->reason);
                }

                return 'already_applied';
            } finally {
                foreach ($leases as $lease) {
                    $lease->release();
                }
            }
        }
        $plan = $this->plan($ids, $anchorId);
        if (! hash_equals($plan['digest'], $digest) || ! $plan['applicable']) {
            throw new RuntimeException('The reviewed plan is stale or non-applicable.');
        }
        $leases = [];
        try {
            sort($ids);
            DB::transaction(function () use ($ids, $plan, &$leases): void {
                DB::table('collections')->whereIn('id', $plan['pending_ids'])->orderBy('id')->lockForUpdate()->get();
                if ($plan['pending_digest'] !== null && PendingInventory::digest((new PendingInventory)->load($plan['pending_ids'])) !== $plan['pending_digest']) {
                    throw new RuntimeException('Pending source inventory changed.');
                }
                foreach ($plan['pending_ids'] as $pendingId) {
                    if (CollectionOwnership::protects($pendingId)) {
                        throw new RuntimeException('Pending source is owned by another worker.');
                    }
                }
                $sources = Release::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($sources->count() !== count($ids)) {
                    throw new RuntimeException('Source disappeared.');
                }
                foreach ($sources as $source) {
                    $xml = $this->nzbs->readNzbContents($source->guid);
                    if (self::active($source) || $xml === false || hash('sha256', $xml) !== $plan['nzb_digests'][$source->id]
                        || $this->metadata($source) !== $plan['metadata'][$source->id]) {
                        throw new RuntimeException('Source or references changed since review.');
                    }
                    $lease = RecoveryLease::acquire($source);
                    if ($lease === null) {
                        throw new RuntimeException('Source is actively processed.');
                    }
                    $leases[(int) $source->id] = $lease;
                }
                $anchor = $sources->firstWhere('id', $plan['anchor']);
                if (DB::table('reconciled_postings')->where('release_id', $anchor->id)->exists()) {
                    throw new RuntimeException('Anchor already has a different reconciliation journal.');
                }
                $files = PendingInventory::decode(json_encode($plan['decision']['accepted'], JSON_THROW_ON_ERROR));
                $xml = $this->nzbs->readNzbContents($anchor->guid);
                $path = $this->nzbs->nzbPath($anchor->guid);
                if ($xml === false || $path === false) {
                    throw new RuntimeException('Missing anchor artifact.');
                }
                $backup = $path.'.before-reconciliation.'.$plan['nzb_digests'][$anchor->id];
                $encoded = gzencode($xml);
                if ($encoded === false || (! is_file($backup) && file_put_contents($backup, $encoded, LOCK_EX) !== strlen($encoded))
                    || gzdecode((string) file_get_contents($backup)) !== $xml) {
                    throw new RuntimeException('Cannot retain original anchor NZB.');
                }
                $acceptedPending = array_values(array_filter($plan['pending_ids'], static fn (int $id): bool => in_array((string) $id, array_column($files, 'sourceId'), true)));
                $postingId = DB::table('reconciled_postings')->insertGetId(['release_id' => $anchor->id, 'digest' => PendingInventory::digest($files),
                    'review_digest' => $plan['digest'], 'state' => 'created',
                    'source_digest' => $acceptedPending === [] ? null : PendingInventory::digest((new PendingInventory)->load($acceptedPending)), 'inventory' => PendingInventory::encode($files),
                    'decision' => json_encode($plan, JSON_THROW_ON_ERROR), 'original_nzb' => base64_encode($xml),
                    'independent_videos' => PostingDecision::hasIndependentVideos($files),
                    'created_at' => now(), 'updated_at' => now()]);
                foreach ($ids as $sourceId) {
                    DB::table('reconciled_posting_inputs')->insert(['posting_id' => $postingId, 'release_id' => $sourceId]);
                }
                foreach ($acceptedPending as $pendingId) {
                    $collection = DB::table('collections')->where('id', $pendingId)->first();
                    DB::table('collections')->where('id', $pendingId)->update(['releases_id' => $anchor->id, 'filecheck' => 4]);
                    DB::table('reconciliation_claims')->updateOrInsert(['collection_id' => $pendingId], [
                        'deadline' => now()->addSeconds((int) config('collection-reconciliation.decision_seconds')),
                        'release_id' => $anchor->id, 'reason' => 'associated', 'owner' => null, 'lease_until' => null,
                    ]);
                    DB::table('reconciled_sources')->insert(['posting_id' => $postingId, 'collection_hash' => bin2hex($collection->collectionhash),
                        'group_id' => $collection->groups_id, 'postdate' => $collection->date, 'source_id' => (string) $pendingId]);
                }
            }, 3);
            $anchor = Release::query()->findOrFail($plan['anchor']);
            $result = app(PostingPublication::class)->write($anchor, $this->nzbs, $leases[(int) $anchor->id]);
            if (! $result->success) {
                throw new RuntimeException($result->reason);
            }

            return 'applied';
        } finally {
            foreach ($leases as $lease) {
                $lease->release();
            }
        }
    }

    public static function active(Release $release): bool
    {
        foreach (['additional_pp_claimed_at', 'recovery_claimed_at', 'nzb_creation_claimed_at'] as $column) {
            $value = $release->getAttribute($column);
            if ($value !== null && strtotime((string) $value) >= ReleaseClaimant::claimStaleBefore()->timestamp) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function metadata(Release $release): array
    {
        $metadata = array_intersect_key($release->getAttributes(), array_flip(['id', 'guid', 'searchname', 'is_trusted_name',
            'movieinfo_id', 'imdbid', 'videos_id', 'tv_episodes_id', 'predb_id', 'categories_id', 'grabs', 'comments']));
        foreach (['users_releases', 'user_downloads', 'comments'] as $table) {
            if (Schema::hasColumn($table, 'releases_id')) {
                $metadata['references'][$table] = DB::table($table)->where('releases_id', $release->id)->count();
            }
        }

        return $metadata;
    }
}
