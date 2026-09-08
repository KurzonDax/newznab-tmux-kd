<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Category;
use App\Models\Release;
use App\Models\Settings;
use App\Services\ObfuscationRecovery\RecoveryReleaseGate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for PC-game metadata admission.
 */
final class GameProcessingCandidateQuery
{
    /**
     * @return Builder<Release>
     */
    public static function query(
        string $groupId = '',
        string $guidChar = '',
        ?int $lookupMode = null,
    ): Builder {
        $resolvedLookupMode = $lookupMode ?? (int) Settings::settingValue('lookupgames');
        $query = Release::query()->tap(static fn ($query) => RecoveryReleaseGate::excludePending($query))
            ->where('categories_id', Category::PC_GAMES)
            ->where('gamesinfo_id', 0);

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
            $query->where('isrenamed', 1);
        }

        return $query;
    }
}
