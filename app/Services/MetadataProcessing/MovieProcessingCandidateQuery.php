<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for movie metadata admission.
 */
final class MovieProcessingCandidateQuery
{
    /**
     * @return Builder<Release>
     */
    public static function query(
        string $groupId = '',
        string $guidChar = '',
        ?int $lookupMode = null,
        bool $renamedOnly = false,
    ): Builder {
        $resolvedLookupMode = $lookupMode ?? (int) Settings::settingValue('lookupimdb');
        $query = Release::query()
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->where(static function (Builder $candidate): void {
                $candidate->whereNull('imdbid')
                    ->orWhereIn('imdbid', imdb_id_pending_values());
            });

        if ($resolvedLookupMode <= 0) {
            return $query->whereRaw('0 = 1');
        }

        if ($groupId !== '') {
            $query->where('groups_id', $groupId);
        }

        if ($guidChar !== '') {
            $query->where('leftguid', $guidChar);
        }

        if ($resolvedLookupMode === 2 || $renamedOnly) {
            $query->where('isrenamed', 1);
        }

        return $query;
    }
}
