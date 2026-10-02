<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\CollectionReconciliation\ArtifactPublication;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\CollectionReconciliation\PostingPublication;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryCollectionOwnership;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use App\Services\ReleaseRepair\DeclaredFileCount;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\MissingFileMatcher;
use App\Services\ReleaseRepair\NzbFileEnvelope;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\ReleaseRepair\OverviewLine;
use App\Services\ReleaseRepair\RecoveredFile;
use App\Services\ReleaseRepair\RecoveredSegment;
use App\Services\ReleaseRepair\RecoveryLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merges headers that arrived after their post became a release into that release's NZB.
 *
 * NZB creation deletes a post's collection, so headers read later -- a slow provider's, or a
 * second provider's -- form a new collection with the same `collectionhash`, which release
 * creation can only discard as a `collectionhash_match` duplicate. When the release is
 * incomplete, its missing segments and files are taken from that late collection instead.
 *
 * Every id it sees is ready to form (sized, evidence-complete or quiet), so no more headers
 * are expected for it.
 */
final class LateHeaderMerger
{
    public function __construct(
        private readonly NzbService $nzb,
        private readonly DeclaredFileCount $declaredFileCount,
        private readonly EvidenceChangedTransition $evidenceChanged,
    ) {}

    /**
     * @param  list<int>  $collectionIds
     * @return list<int> Ids the caller must drop from this pass: merged, already held, or
     *                   deferred to a later pass. The rest keep today's duplicate path.
     */
    public function merge(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $candidates = Release::query()
            ->join('collections as late', 'late.collectionhash', '=', 'releases.collectionhash')
            ->whereIn('late.id', $collectionIds)
            ->where('releases.nzbstatus', NzbService::NZB_ADDED)
            ->where('releases.completion', '<', 100)
            ->orderBy('late.id')
            ->get(['releases.*', 'late.id as late_collection_id', 'late.fromname as late_collection_fromname']);

        $drop = [];
        foreach ($candidates as $release) {
            $collectionId = (int) $release->getAttribute('late_collection_id');
            $collectionPoster = (string) $release->getAttribute('late_collection_fromname');
            $release->setRawAttributes(array_diff_key($release->getAttributes(),
                ['late_collection_id' => true, 'late_collection_fromname' => true]), true);

            if ($this->mergeInto($release, $collectionId, $collectionPoster)) {
                $drop[] = $collectionId;
            }
        }

        return $drop;
    }

    /**
     * Delete a merged late collection, unless headers arrived for it while it was being merged.
     *
     * Header ingest locks a collection row before writing its binaries and parts, so counting
     * the parts after taking that lock sees every committed ingest.
     */
    public function removeLateCollection(int $collectionId, int $partsRead): bool
    {
        return DB::transaction(static function () use ($collectionId, $partsRead): bool {
            // Ownership is rechecked under the lock ingestion takes: recovery or reconciliation
            // may have claimed the collection since this pass selected it.
            $collection = DB::table('collections')->where('id', $collectionId)
                ->tap(static fn ($query) => RecoveryCollectionOwnership::exclude($query, populationIds: [$collectionId], currentRead: true))
                ->tap(static fn ($query) => CollectionOwnership::exclude($query, populationIds: [$collectionId], currentRead: true))
                ->lockForUpdate()->first(['id']);
            if ($collection === null) {
                return false;
            }

            $parts = (int) DB::table('parts as p')
                ->join('binaries as b', 'b.id', '=', 'p.binaries_id')
                ->where('b.collections_id', $collectionId)
                ->count();
            if ($parts !== $partsRead) {
                return false;
            }

            DB::table('parts')->whereIn('binaries_id', DB::table('binaries')->where('collections_id', $collectionId)->select('id'))->delete();
            DB::table('binaries')->where('collections_id', $collectionId)->delete();
            DB::table('collections')->where('id', $collectionId)->delete();

            return true;
        });
    }

    /**
     * @return bool Whether the caller drops the collection from this pass.
     */
    private function mergeInto(Release $release, int $collectionId, string $collectionPoster): bool
    {
        if (app(RecoveryIdentityPolicy::class)->publication((int) $release->id) !== null
            || ArtifactPublication::handles((string) $release->guid)
            || PostingPublication::has((int) $release->id)) {
            return false;
        }

        $lease = RecoveryLease::acquire($release);
        if ($lease === null) {
            // Repair, re-scan or additional processing holds the release; try next pass.
            return true;
        }

        try {
            $original = $this->nzb->readNzbContents((string) $release->guid);
            $document = $original === false ? null : NzbRepairDocument::load($original);
            $envelope = $document?->envelope();
            if ($original === false || $document === null || $envelope === null) {
                // Our storage failed, not the release: leave the collection for a later pass.
                return true;
            }

            $completionBefore = (float) $release->completion;
            $parts = $this->lateParts($collectionId);
            $merge = $this->apply($release, $document, $envelope, $collectionPoster, $parts);
            if ($merge === null) {
                // Nothing in it belongs to this release: today's duplicate path deletes it.
                return false;
            }

            $completionAfter = $completionBefore;
            if ($merge['changed']) {
                $replaced = $this->nzb->replaceNzbContentsWithLease((string) $release->guid, $document->toXml(), $lease, hash('sha256', $original));
                if (! $replaced->success) {
                    return true;
                }
                $this->evidenceChanged->apply($release, $document, $merge['declared']);
                $completionAfter = $document->measure($merge['declared'])->percentage();
            }

            $this->removeLateCollection($collectionId, \count($parts));

            Log::info('late_header_merge', [
                'release_id' => (int) $release->id,
                'collection_id' => $collectionId,
                'segments_added' => $merge['segments'],
                'files_added' => $merge['files'],
                'completion_before' => $completionBefore,
                'completion_after' => $completionAfter,
            ]);

            return true;
        } finally {
            $lease->release();
        }
    }

    /**
     * Add the late collection's segments and files to the document.
     *
     * @param  list<object{name: string, totalparts: int|string, partnumber: int|string, messageid: string, size: int|string, binaries_id: int|string}>  $parts
     * @return array{segments: int, files: int, changed: bool, declared: int}|null Null when no binary belongs to the release;
     *                                                                             segments counts those added to held files.
     */
    private function apply(Release $release, NzbRepairDocument $document, NzbFileEnvelope $envelope, string $collectionPoster, array $parts): ?array
    {
        $declared = $this->declaredFileCount->resolve($release, $document);
        $subjects = array_values($document->subjects());
        $heldIndices = array_values(array_filter(array_map(
            static fn (string $subject): ?int => MissingFileMatcher::fileIndexOf($subject),
            $subjects,
        ), static fn (?int $index): bool => $index !== null));
        $poster = MissingFileMatcher::normalizePoster($envelope->poster);

        // The envelope's poster speaks for every file: the NZB writer stamps each from the
        // same collection row.
        if (MissingFileMatcher::normalizePoster($collectionPoster) !== $poster) {
            return null;
        }

        $binaries = [];
        foreach ($parts as $part) {
            $binaries[(int) $part->binaries_id][] = $part;
        }

        $positions = [];
        foreach ($document->subjects() as $position => $subject) {
            $positions[$subject][] = $position;
        }
        $held = $document->segments();
        $everyFileIndexed = \count($heldIndices) === \count($subjects);
        $matcher = new MissingFileMatcher($envelope->poster, $declared, $subjects, $heldIndices);

        $matched = false;
        $newSegments = [];
        /** @var array<int, RecoveredFile> $newFiles */
        $newFiles = [];
        foreach ($binaries as $binaryParts) {
            $first = $binaryParts[0];
            $subject = str_replace("\x0F", '', NzbService::buildBinarySubject((string) $first->name, (int) $first->totalparts));
            $at = $positions[$subject] ?? [];

            if (\count($at) === 1) {
                $position = $at[0];
                $missing = $this->missingSegments($binaryParts, $held[$position] ?? []);
                if ($missing === null) {
                    continue;
                }
                $matched = true;
                if ($missing !== []) {
                    $newSegments[$position] = $missing + ($newSegments[$position] ?? []);
                }

                continue;
            }

            if ($at !== [] || ! $everyFileIndexed) {
                continue;
            }

            foreach ($binaryParts as $part) {
                $line = OverviewLine::parse([
                    'Subject' => $part->name.' ('.$part->partnumber.'/'.$part->totalparts.')',
                    'Message-ID' => $part->messageid,
                    'From' => $collectionPoster,
                    'Bytes' => $part->size,
                ]);
                if ($line === null || ! $matcher->matches($line)) {
                    continue;
                }
                $matched = true;
                $existing = $newFiles[$line->fileIndex] ?? new RecoveredFile($line->fileIndex, $line->nzbSubject(), []);
                $segments = $existing->segments;
                $segments[$line->segmentNumber] ??= new RecoveredSegment($line->messageId, $line->bytes);
                $newFiles[$line->fileIndex] = new RecoveredFile($existing->fileIndex, $existing->subject, $segments);
            }
        }

        if (! $matched) {
            return null;
        }

        $segments = $document->addSegments($newSegments);
        $fileSegments = $document->addFiles($newFiles, $envelope);

        return ['segments' => $segments, 'files' => \count($newFiles), 'changed' => $segments + $fileSegments > 0, 'declared' => $declared];
    }

    /**
     * The parts of one late binary that a held file lacks, or null when the binary is a
     * different post that happens to share the file's name.
     *
     * @param  list<object{partnumber: int|string, messageid: string}>  $binaryParts
     * @param  array<int, string>  $held  Segment number => message-ID of the held file.
     * @return array<int, string>|null Segment number => message-ID.
     */
    private function missingSegments(array $binaryParts, array $held): ?array
    {
        $missing = [];
        foreach ($binaryParts as $part) {
            $number = (int) $part->partnumber;
            $messageId = NzbService::normalizeSegmentMessageId((string) $part->messageid);
            if (isset($held[$number])) {
                // Message-IDs are global: one difference proves a different post.
                if ($held[$number] !== $messageId) {
                    return null;
                }

                continue;
            }
            if ($number > 0 && $messageId !== '') {
                $missing[$number] = $messageId;
            }
        }

        return $missing;
    }

    /**
     * @return list<object{binaries_id: int|string, name: string, totalparts: int|string, partnumber: int|string, messageid: string, size: int|string}>
     */
    private function lateParts(int $collectionId): array
    {
        /** @var list<object{binaries_id: int|string, name: string, totalparts: int|string, partnumber: int|string, messageid: string, size: int|string}> */
        return DB::table('binaries as b')
            ->join('parts as p', 'p.binaries_id', '=', 'b.id')
            ->where('b.collections_id', $collectionId)
            ->orderBy('b.id')
            ->orderBy('p.partnumber')
            ->get(['b.id as binaries_id', 'b.name', 'b.totalparts', 'p.partnumber', 'p.messageid', 'p.size'])
            ->all();
    }
}
