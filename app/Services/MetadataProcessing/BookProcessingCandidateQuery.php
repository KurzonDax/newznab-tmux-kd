<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for book and audiobook metadata admission.
 */
final class BookProcessingCandidateQuery
{
    /**
     * @return Builder<Release>
     */
    public static function query(
        string $groupId = '',
        string $guidChar = '',
        ?int $lookupMode = null,
    ): Builder {
        $resolvedLookupMode = $lookupMode ?? (int) Settings::settingValue('lookupbooks');
        $query = Release::query()
            ->where(static function (Builder $category): void {
                $category->whereBetween('categories_id', [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN])
                    ->orWhere('categories_id', Category::MUSIC_AUDIOBOOK);
            });

        if ($resolvedLookupMode <= 0) {
            return $query->whereRaw('0 = 1');
        }

        if ($groupId !== '') {
            $query->where('groups_id', $groupId);
        }

        if ($guidChar !== '') {
            $query->where('leftguid', 'like', $guidChar.'%');
        }

        if ($resolvedLookupMode === 2) {
            return $query->whereNull('bookinfo_id')->where('isrenamed', 1);
        }

        return $query->where(static function (Builder $candidate): void {
            $candidate->where('isrenamed', 0)
                ->orWhereNull('bookinfo_id')
                ->orWhere('searchname', 'like', 'N:/NZB%')
                ->orWhere('searchname', 'like', 'N_NZB_%')
                ->orWhere('name', 'like', 'N:/NZB%')
                ->orWhere('name', 'like', 'N_NZB_%');
        });
    }
}
