<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\Nzb\CompletionSignals;
use App\Services\Nzb\CompletionTally;
use Illuminate\Support\Facades\DB;

/**
 * Measures how complete a collection is from its own binaries and parts.
 *
 * Both numbers completion needs -- the segments we hold and the segments the subjects declared --
 * are sitting in the CBP rows at release-creation time. Reading them there is exact, and it is the
 * last chance to: NZB creation deletes the rows, after which the only way back to those numbers is
 * to gunzip the NZB and regex the totals out of the subjects the writer itself generated.
 *
 * Measuring here rather than inside the NZB writer also means completion exists even when the NZB
 * write is deferred: creation can fail transiently and retry through the claim-token machinery.
 *
 * The aggregate below is {@see CompletionTally} expressed in SQL, for the one
 * caller that has rows rather than files. The two must stay in lockstep -- the same fixtures are
 * pinned to the same percentages in CompletionSignalsTest and ReleaseCreationCompletionTest, so a
 * divergence in either fails there rather than silently mismeasuring a release.
 */
final class CollectionCompletionMeasurer
{
    /** Collection ids per query, matching the batching elsewhere on this path. */
    private const int CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $declaredFilesByCollection  Collection id => `collections.totalfiles`,
     *                                                      the `[n/N]` file index the headers declared.
     * @return array<int, CompletionSignals> Signals keyed by collection id; collections with no
     *                                       binaries are absent.
     */
    public function measure(array $declaredFilesByCollection): array
    {
        $signals = [];

        foreach (array_chunk(array_keys($declaredFilesByCollection), self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

            $rows = DB::select(
                "SELECT f.collections_id AS collections_id,
                        COUNT(*) AS files_present,
                        COALESCE(SUM(f.segments), 0) AS segments_present,
                        COALESCE(SUM(f.declared), 0) AS segments_declared,
                        COALESCE(MAX(f.segments), 0) AS max_segments_per_file,
                        COUNT(DISTINCT f.declared) AS distinct_declared_totals,
                        COALESCE(MAX(f.declared), 0) AS declared_per_file
                 FROM (
                     SELECT b.collections_id AS collections_id,
                            b.id AS binaries_id,
                            COALESCE(b.totalparts, 0) AS declared,
                            COUNT(p.number) AS segments
                     FROM binaries b
                     LEFT JOIN parts p ON p.binaries_id = b.id
                     WHERE b.collections_id IN ({$placeholders})
                     GROUP BY b.collections_id, b.id, b.totalparts
                 ) f
                 GROUP BY f.collections_id",
                $chunk
            );

            foreach ($rows as $row) {
                $collectionId = (int) $row->collections_id;

                $signals[$collectionId] = new CompletionSignals(
                    filesPresent: (int) $row->files_present,
                    segmentsPresent: (int) $row->segments_present,
                    segmentsDeclared: (int) $row->segments_declared,
                    filesDeclared: max(0, $declaredFilesByCollection[$collectionId] ?? 0),
                    maxSegmentsPerFile: (int) $row->max_segments_per_file,
                    distinctDeclaredTotals: (int) $row->distinct_declared_totals,
                    maxDeclaredPerFile: (int) $row->declared_per_file,
                );
            }
        }

        return $signals;
    }
}
