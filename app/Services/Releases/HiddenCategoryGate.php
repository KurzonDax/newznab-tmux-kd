<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Enums\BrowseRoot;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refuses a single release to a signed-in user who has hidden its category.
 *
 * Lists and search drop hidden categories through the same exclusion list;
 * this is the check for the web pages and downloads that serve one release
 * by its guid or id. The API and RSS keep their own behaviour. Page
 * controllers read the same list from their loaded user data.
 */
final class HiddenCategoryGate
{
    private const int CACHE_SECONDS = 300;

    /**
     * The category ids the user has hidden: every sub-category of a root the
     * user may not view, plus the user's own sub-category exclusions.
     *
     * Cached per user; if the store is unreachable (e.g. Redis down), the list
     * is computed directly.
     *
     * @return list<int>
     */
    public function hiddenCategoryIds(int $userId): array
    {
        $load = static fn (): array => User::getCategoryExclusionById($userId);

        try {
            $ids = Cache::remember(User::categoryExclusionCacheKey($userId), self::CACHE_SECONDS, $load);
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::debug('HiddenCategoryGate cache bypassed: '.$e->getMessage());
            }
            $ids = $load();
        }

        return array_values(array_map(static fn (mixed $id): int => (int) $id, (array) $ids));
    }

    /**
     * Whether the user has hidden this category; nothing is hidden without a signed-in user.
     */
    public function hides(int|string|null $userId, mixed $categoryId): bool
    {
        return $userId !== null && in_array((int) $categoryId, $this->hiddenCategoryIds((int) $userId), true);
    }

    /**
     * The name the category-disabled page shows: the root's name when the
     * whole root is hidden, "Root - Sub-category" when only the sub-category is.
     */
    public function hiddenName(User $user, int $categoryId): string
    {
        $root = BrowseRoot::fromCategoryId($categoryId);
        if (in_array($root->categoryId(), $user->hiddenRootCategoryIds(), true)) {
            return $root->hiddenName();
        }

        $title = (string) Category::query()->whereKey($categoryId)->value('title');

        return $root->hiddenName().' - '.$title;
    }

    /**
     * The category-disabled page, status 403.
     */
    public function deniedPage(User $user, int $categoryId): Response
    {
        return response()->view('errors.category-disabled', [
            'category' => $this->hiddenName($user, $categoryId),
            'isSubcategory' => ! in_array(BrowseRoot::fromCategoryId($categoryId)->categoryId(), $user->hiddenRootCategoryIds(), true),
        ], 403);
    }

    /**
     * The JSON refusal, status 403, carrying no release data.
     */
    public function deniedJson(User $user, int $categoryId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->hiddenName($user, $categoryId).' is hidden in your account preferences.',
        ], 403);
    }
}
