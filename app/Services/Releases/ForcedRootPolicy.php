<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Category;
use App\Models\UsenetGroup;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Selects the one Forced root that governs a release's complete group set.
 */
final class ForcedRootPolicy
{
    /** @var list<int> */
    private const array VALID_ROOT_CATEGORY_IDS = [
        Category::OTHER_ROOT,
        Category::GAME_ROOT,
        Category::MOVIE_ROOT,
        Category::MUSIC_ROOT,
        Category::PC_ROOT,
        Category::TV_ROOT,
        Category::XXX_ROOT,
        Category::BOOKS_ROOT,
    ];

    /**
     * @param  iterable<int|string>  $associatedGroupIds
     * @return list<int>
     */
    public function groupIds(int|string $primaryGroupId, iterable $associatedGroupIds): array
    {
        $groupIds = [(int) $primaryGroupId];

        foreach ($associatedGroupIds as $groupId) {
            $groupIds[] = (int) $groupId;
        }

        return array_values(array_unique($groupIds));
    }

    /**
     * @param  iterable<UsenetGroup>  $groups
     */
    public function select(int|string $primaryGroupId, iterable $groups): ?int
    {
        $primaryGroupId = (int) $primaryGroupId;
        $associatedForcedRoots = [];

        foreach ($groups as $group) {
            $forcedRoot = $this->validRoot($group->forced_root_categories_id);
            if ($forcedRoot === null) {
                continue;
            }

            if ((int) $group->id === $primaryGroupId) {
                return $forcedRoot;
            }

            $associatedForcedRoots[] = $forcedRoot;
        }

        return $associatedForcedRoots === [] ? null : min($associatedForcedRoots);
    }

    public function selectForRelease(int|string $primaryGroupId, int $releaseId): ?int
    {
        $associatedGroupIds = DB::table('releases_groups')
            ->where('releases_id', $releaseId)
            ->pluck('groups_id')
            ->all();

        $groups = UsenetGroup::query()
            ->whereIn('id', $this->groupIds($primaryGroupId, $associatedGroupIds))
            ->get(['id', 'forced_root_categories_id']);

        return $this->select($primaryGroupId, $groups);
    }

    /**
     * Constrain a release query to rows whose selected Forced root is $rootCategoryId.
     */
    public static function applySelectedRoot(
        QueryBuilderContract $query,
        int $rootCategoryId,
        string $releaseAlias = 'r',
    ): void {
        $query->where(function (QueryBuilderContract $selectedRoot) use ($rootCategoryId, $releaseAlias): void {
            $selectedRoot
                ->whereExists(self::primaryForcedRootQuery($releaseAlias, $rootCategoryId))
                ->orWhere(function (QueryBuilderContract $associatedRoot) use ($rootCategoryId, $releaseAlias): void {
                    $associatedRoot
                        ->whereNotExists(self::primaryForcedRootQuery($releaseAlias))
                        ->whereExists(self::associatedForcedRootQuery($releaseAlias, $rootCategoryId))
                        ->whereNotExists(self::lowerAssociatedForcedRootQuery($releaseAlias, $rootCategoryId));
                });
        });
    }

    /**
     * Constrain a release query to rows with no valid Forced root in any group.
     */
    public static function applyNoSelectedRoot(QueryBuilderContract $query, string $releaseAlias = 'r'): void
    {
        $query
            ->whereNotExists(self::primaryForcedRootQuery($releaseAlias))
            ->whereNotExists(self::associatedForcedRootQuery($releaseAlias));
    }

    private function validRoot(mixed $rootCategoryId): ?int
    {
        $rootCategoryId = $rootCategoryId === null ? null : (int) $rootCategoryId;

        return $rootCategoryId !== null && in_array($rootCategoryId, self::VALID_ROOT_CATEGORY_IDS, true)
            ? $rootCategoryId
            : null;
    }

    private static function primaryForcedRootQuery(string $releaseAlias, ?int $rootCategoryId = null): Builder
    {
        $query = UsenetGroup::query()
            ->getQuery()
            ->selectRaw('1')
            ->from('usenet_groups as forced_primary_group')
            ->whereColumn('forced_primary_group.id', $releaseAlias.'.groups_id');

        return $rootCategoryId === null
            ? $query->whereIn('forced_primary_group.forced_root_categories_id', self::VALID_ROOT_CATEGORY_IDS)
            : $query->where('forced_primary_group.forced_root_categories_id', $rootCategoryId);
    }

    private static function associatedForcedRootQuery(string $releaseAlias, ?int $rootCategoryId = null): Builder
    {
        $query = self::associatedGroupQuery($releaseAlias);

        return $rootCategoryId === null
            ? $query->whereIn('forced_associated_group.forced_root_categories_id', self::VALID_ROOT_CATEGORY_IDS)
            : $query->where('forced_associated_group.forced_root_categories_id', $rootCategoryId);
    }

    private static function lowerAssociatedForcedRootQuery(string $releaseAlias, int $rootCategoryId): Builder
    {
        return self::associatedGroupQuery($releaseAlias)
            ->whereIn('forced_associated_group.forced_root_categories_id', self::VALID_ROOT_CATEGORY_IDS)
            ->where('forced_associated_group.forced_root_categories_id', '<', $rootCategoryId);
    }

    private static function associatedGroupQuery(string $releaseAlias): Builder
    {
        return UsenetGroup::query()
            ->getQuery()
            ->selectRaw('1')
            ->from('releases_groups as forced_release_group')
            ->join(
                'usenet_groups as forced_associated_group',
                'forced_associated_group.id',
                '=',
                'forced_release_group.groups_id',
            )
            ->whereColumn('forced_release_group.releases_id', $releaseAlias.'.id');
    }
}
