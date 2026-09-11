<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use App\Services\CollectionReconciliation\BundleIdentity;
use App\Services\ObfuscationRecovery\RecoveryIdentityPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for movie metadata admission.
 */
final class MovieProcessingCandidateQuery
{
    public const MAX_ATTEMPTS = 4;

    public const RETRY_HOURS = 6;

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
        $query = Release::query()->whereRaw(RecoveryIdentityPolicy::singleItemSql())->whereRaw(BundleIdentity::singleItemSql())
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), static fn ($query) => $query->forceIndex('ix_releases_imdbid_password_cat_postdate'))
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->where(static function (Builder $candidate): void {
                $candidate->where(function (Builder $pending): void {
                    $pending->whereNull('imdbid')
                        ->where(fn (Builder $attempts) => $attempts->whereNull('imdb_lookup_attempts')->orWhere('imdb_lookup_attempts', '<', self::MAX_ATTEMPTS))
                        ->where(fn (Builder $due) => $due->whereNull('imdb_lookup_attempted_at')->orWhere('imdb_lookup_attempted_at', '<=', now()->subHours(self::RETRY_HOURS)));
                })
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
