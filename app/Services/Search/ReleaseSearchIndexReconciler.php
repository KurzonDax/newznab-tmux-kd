<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Facades\Search;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\ReleaseSearchIndexDocument;

final class ReleaseSearchIndexReconciler
{
    public const string CURSOR_CACHE_KEY = 'search:release-index-reconcile:cursor';

    public function __construct(private readonly SearchDriverInterface $driver) {}

    /**
     * Compare and repair one bounded, cursor-ordered union of DB rows and index documents.
     *
     * @return array{cursor: int, scanned: int, resynced: int, deleted: int, wrapped: bool}
     */
    public function reconcile(int $afterId, int $limit): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(10000, $limit));
        $indexed = $this->driver->releaseDocumentsAfterId($afterId, $limit);
        $stored = ReleaseIndexProjection::query()
            ->where('r.id', '>', $afterId)
            ->orderBy('r.id')
            ->limit($limit)
            ->get()
            ->mapWithKeys(function (object $row): array {
                $document = ReleaseSearchIndexDocument::normalizeForReconciliation((array) $row);

                return [$document['id'] => $document];
            })
            ->all();

        $ids = array_values(array_unique([...array_keys($stored), ...array_keys($indexed)]));
        sort($ids, SORT_NUMERIC);
        $ids = array_slice($ids, 0, $limit);

        if ($ids === []) {
            return ['cursor' => 0, 'scanned' => 0, 'resynced' => 0, 'deleted' => 0, 'wrapped' => $afterId > 0];
        }

        $resyncIds = [];
        $orphanIds = [];

        foreach ($ids as $id) {
            $storedDocument = $stored[$id] ?? null;
            $indexedDocument = $indexed[$id] ?? null;

            if ($storedDocument === null) {
                $orphanIds[] = $id;

                continue;
            }

            if ($indexedDocument === null || $storedDocument !== $indexedDocument) {
                $resyncIds[] = $id;
            }
        }

        foreach ($resyncIds as $id) {
            Search::updateRelease($id);
        }

        if ($orphanIds !== []) {
            Search::deleteReleases($orphanIds);
        }

        return [
            'cursor' => (int) end($ids),
            'scanned' => count($ids),
            'resynced' => count($resyncIds),
            'deleted' => count($orphanIds),
            'wrapped' => false,
        ];
    }
}
