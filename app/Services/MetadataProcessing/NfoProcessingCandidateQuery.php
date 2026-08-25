<?php

declare(strict_types=1);

namespace App\Services\MetadataProcessing;

use App\Models\Release;
use App\Models\Settings;
use App\Services\NfoService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for NFO processing admission.
 */
final class NfoProcessingCandidateQuery
{
    /**
     * @return Builder<Release>
     */
    public static function query(
        string $groupId = '',
        string $guidChar = '',
        ?int $lookupMode = null,
    ): Builder {
        $resolvedLookupMode = $lookupMode ?? (int) Settings::settingValue('lookupnfo');
        $query = Release::query()->from('releases as r')->select('r.*');

        if ($resolvedLookupMode !== 1) {
            return $query->whereRaw('0 = 1');
        }

        $query->whereRaw('1 = 1 '.NfoService::NfoQueryString());

        if ($groupId !== '') {
            $query->where('r.groups_id', $groupId);
        }

        if ($guidChar !== '') {
            $query->where('r.leftguid', $guidChar);
        }

        return $query;
    }
}
