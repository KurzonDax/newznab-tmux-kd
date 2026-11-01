<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use App\Models\Category;
use App\Services\Releases\ForcedRootPolicy;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Support\Facades\DB;

final class RecoveryFormationPolicy
{
    public function initialCategory(object $collection): int
    {
        $policy = new ForcedRootPolicy;

        return $policy->constrainCategory(Category::OTHER_MISC, $policy->selectForCollection($collection));
    }

    public function blockedReason(object $collection, ?int $initialCategory = null, int $plannedBytes = 0): ?string
    {
        // A provider can list 0 bytes for every part; the sealed plan still knows the file's size.
        $size = max((int) $collection->filesize, $plannedBytes);
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
        if ($size < max((int) ($group->minsizetoformrelease ?? 0), $settings->minSizeToFormRelease)) {
            return 'minimum_size';
        }
        if ($settings->maxSizeToFormRelease > 0 && $size > $settings->maxSizeToFormRelease) {
            return 'maximum_size';
        }
        $category = DB::table('categories')->where('id', $initialCategory ?? $this->initialCategory($collection))->first();
        if ($category === null || (isset($category->status) && (int) $category->status !== 1)) {
            return 'initial_category_disabled';
        }
        if ($size < (int) ($category->minsizetoformrelease ?? 0)) {
            return 'category_minimum_size';
        }

        return null;
    }
}
