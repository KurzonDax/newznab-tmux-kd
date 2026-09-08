<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Category;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Support\Facades\DB;

final class RecoveryFormationPolicy
{
    public function blockedReason(object $collection): ?string
    {
        $settings = ProcessReleasesSettings::forDatabase(DB::table('settings')->whereIn('name', [
            'minsizetoformrelease', 'maxsizetoformrelease', 'minfilestoformrelease',
        ])->pluck('value', 'name')->all());
        $group = DB::table('usenet_groups')->where('id', $collection->groups_id)->first();
        if ($group === null) {
            return 'group_missing';
        }
        if ((int) $collection->totalfiles < max((int) ($group->minfilestoformrelease ?? 0), $settings->minFilesToFormRelease)) {
            return 'minimum_file_count';
        }
        if ((int) $collection->filesize < max((int) ($group->minsizetoformrelease ?? 0), $settings->minSizeToFormRelease)) {
            return 'minimum_size';
        }
        if ($settings->maxSizeToFormRelease > 0 && (int) $collection->filesize > $settings->maxSizeToFormRelease) {
            return 'maximum_size';
        }
        $category = DB::table('categories')->where('id', Category::OTHER_MISC)->first();
        if ($category === null || (isset($category->status) && (int) $category->status !== 1)) {
            return 'initial_category_disabled';
        }
        if ((int) $collection->filesize < (int) ($category->minsizetoformrelease ?? 0)) {
            return 'category_minimum_size';
        }

        return null;
    }
}
