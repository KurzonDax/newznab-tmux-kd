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
     * How many size-band candidates the normalized fallback inspects before giving up.
     */
    private const int NORMALIZED_CANDIDATE_LIMIT = 25;

    /**
     * @var list<string>
     */
    private const array SELECTED_COLUMNS = ['id', 'predb_id', 'searchname', 'fromname', 'size', 'name'];

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

        $dup = $query->first(self::SELECTED_COLUMNS);

        if ($dup !== null) {
            return [$dup, $this->resolveReason($dup, $searchName, $predbId)];
        }

        if ($searchName !== '') {
            $dup = $this->findNormalizedMatch($lowSize, $highSize, $searchName);

            if ($dup !== null) {
                return [$dup, 'normalized_searchname_match'];
            }
        }

        return [null, null];
    }

    /**
     * Retry the searchname match with raw-subject leftovers stripped from both sides.
     *
     * Only index-friendly predicates reach the database: an equality on the
     * normalized candidate (stored value already clean) plus two prefix LIKEs
     * (stored value still carries a suffix, quoted or not). Whether a candidate
     * really is the same upload is decided in PHP, so no REPLACE() scan of the
     * releases table is needed.
     */
    private function findNormalizedMatch(int $lowSize, int $highSize, string $searchName): ?Release
    {
        $normalized = ReleaseNameNormalizer::normalize($searchName);

        if ($normalized === '') {
            return null;
        }

        // Wildcards are left unescaped on purpose: LIKE only widens the candidate
        // set, and every candidate is verified below.
        $candidates = Release::query()
            ->whereBetween('size', [$lowSize, $highSize])
            ->where(function (Builder $w) use ($normalized): void {
                $w->where('searchname', $normalized)
                    ->orWhere('searchname', 'like', $normalized.'.%')
                    ->orWhere('searchname', 'like', '"'.$normalized.'%');
            })
            ->limit(self::NORMALIZED_CANDIDATE_LIMIT)
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
