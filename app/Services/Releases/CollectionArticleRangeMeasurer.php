<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Support\Facades\DB;

/**
 * The span of article numbers a collection's parts occupied on the primary provider.
 *
 * Read at release creation because that is the last moment it exists: NZB creation deletes the
 * CBP rows, and the NZB itself keeps neither article numbers nor a real date window -- every
 * `<file date>` is the collection's single date, written once per release.
 *
 * A header re-scan looking for the files that were missed entirely needs an article range to
 * XOVER. With these anchors it is a short window around what we know the collection spanned;
 * without them the range has to be bisected out of the group by date, which costs a round trip
 * per probe and is only as accurate as the release's postdate.
 */
final class CollectionArticleRangeMeasurer
{
    /** Collection ids per query, matching the batching in {@see CollectionCompletionMeasurer}. */
    private const int CHUNK_SIZE = 500;

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, array{first: int, last: int}> Keyed by collection id; collections whose
     *                                                  binaries hold no parts are absent.
     */
    public function measure(array $collectionIds): array
    {
        $ranges = [];

        foreach (array_chunk($collectionIds, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

            $rows = DB::select(
                "SELECT b.collections_id AS collections_id,
                        MIN(p.number) AS first_article,
                        MAX(p.number) AS last_article
                 FROM binaries b
                 INNER JOIN parts p ON p.binaries_id = b.id
                 WHERE b.collections_id IN ({$placeholders})
                 GROUP BY b.collections_id",
                $chunk
            );

            foreach ($rows as $row) {
                if ($row->first_article === null || $row->last_article === null) {
                    continue;
                }

                $ranges[(int) $row->collections_id] = [
                    'first' => (int) $row->first_article,
                    'last' => (int) $row->last_article,
                ];
            }
        }

        return $ranges;
    }
}
