<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Support\ReleaseNameNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds an existing release that should be treated as a duplicate of an incoming import.
 *
 * Uses (predb_id OR searchname) within a configurable size band. Falls back to raw {@see Release::$name}
 * when {@see Release::$searchname} is empty and there is no predb id.
 *
 * A repost of the same upload can reach us with a searchname that still carries
 * raw-subject leftovers (wrapping quotes, a .partNNN.rar suffix), so an exact
 * miss is retried against {@see ReleaseNameNormalizer}-normalized values.
 */
final class ReleaseDuplicateFinder
{
    /**
     * @var list<string>
     */
    private const array SELECTED_COLUMNS = [
        'id',
        'predb_id',
        'searchname',
        'searchname_normalized',
        'fromname',
        'size',
        'name',
        'completion',
        'guid',
        'totalpart',
        'declaredfiles',
        'nzbstatus',
    ];

    /**
     * @return array{0: ?Release, 1: ?string} Tuple of matched release (if any) and dedupe reason for logging.
     */
    public function findDuplicate(
        string $cleanRelName,
        string $searchName,
        int $predbId,
        int $filesize,
    ): array {
        $tolerance = (float) config('nntmux.release_dedupe_size_tolerance', 0.05);
        $lowSize = (int) floor($filesize * (1 - $tolerance));
        $highSize = (int) ceil($filesize * (1 + $tolerance));

        $query = Release::query()
            ->whereBetween('size', [$lowSize, $highSize]);

        if ($predbId > 0 || $searchName !== '') {
            $query->where(function (Builder $w) use ($searchName, $predbId): void {
                if ($predbId > 0) {
                    $w->where('predb_id', $predbId);
                    if ($searchName !== '') {
                        $w->orWhere('searchname', $searchName);
                    }
                } elseif ($searchName !== '') {
                    $w->where('searchname', $searchName);
                } else {
                    $w->whereRaw('1 = 0');
                }
            });
        } else {
            $query->where('name', $cleanRelName);
        }

        $dup = $query
            ->orderByDesc('completion')
            ->orderBy('id')
            ->first(self::SELECTED_COLUMNS);

        if ($dup !== null && $predbId > 0 && (int) $dup->predb_id === $predbId) {
            return [$dup, $this->resolveReason($dup, $searchName, $predbId)];
        }

        if ($searchName !== '') {
            $normalizedMatch = $this->findNormalizedMatch($lowSize, $highSize, $searchName);

            if ($normalizedMatch !== null) {
                $reason = (string) $normalizedMatch->searchname === $searchName
                    ? 'searchname_match'
                    : 'normalized_searchname_match';

                return [$normalizedMatch, $reason];
            }
        }

        if ($dup !== null) {
            return [$dup, $this->resolveReason($dup, $searchName, $predbId)];
        }

        return [null, null];
    }

    /**
     * Retry the searchname match with raw-subject leftovers stripped from both sides.
     *
     * The persisted identity makes the database prefilter complete and indexable.
     * PHP verification remains as a cheap invariant check against writer drift.
     */
    private function findNormalizedMatch(int $lowSize, int $highSize, string $searchName): ?Release
    {
        $normalized = ReleaseNameNormalizer::normalize($searchName);

        if ($normalized === '') {
            return null;
        }

        $candidates = Release::query()
            ->whereBetween('size', [$lowSize, $highSize])
            ->where('searchname_normalized', $normalized)
            ->orderByDesc('completion')
            ->orderBy('id')
            ->get(self::SELECTED_COLUMNS);

        foreach ($candidates as $candidate) {
            if (ReleaseNameNormalizer::normalize((string) $candidate->searchname) === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveReason(Release $dup, string $searchName, int $predbId): string
    {
        if ($predbId > 0 && (int) $dup->predb_id === $predbId) {
            return 'predb_id_match';
        }

        if ($searchName !== '' && (string) $dup->searchname === $searchName) {
            return 'searchname_match';
        }

        return 'name_match_fallback';
    }
}
