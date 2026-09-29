<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\BrowseRoot;
use App\Models\Category;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClearanceMiddleware
{
    /**
     * Handle an incoming request.
     *
     *
     * @throws \Exception
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin/Moderator bypass only applies to Admin section pages
        // They should still respect their personal category permission settings
        if ($request->is(['Admin', 'Admin/*'])) {
            if (! $user->hasAnyRole(['Admin', 'Moderator'])) {
                abort(403, 'Unauthorized access to admin area');
            }

            return $next($request);
        }

        // Get the path for pattern matching
        $path = $request->path();

        // Check for browse routes with subcategory - e.g., browse/Movies/HD
        if (preg_match('#^browse/([^/]+)/([^/]+)$#i', $path, $matches)) {
            $parentCategoryName = $matches[1];
            $subcategoryName = $matches[2];

            // First check if the main category is blocked
            $blockedCategory = $this->checkMainCategoryPermission($user, $parentCategoryName);
            if ($blockedCategory) {
                return $this->abortCategoryDisabled($blockedCategory);
            }

            // Then check if the subcategory is excluded (unless it's "All")
            if (strtolower($subcategoryName) !== 'all') {
                $blockedSubcategory = $this->checkSubcategoryExclusion($user, $parentCategoryName, $subcategoryName);
                if ($blockedSubcategory) {
                    return $this->abortSubcategoryDisabled($blockedSubcategory);
                }
            }

            return $next($request);
        }

        // Check for browse routes without subcategory - e.g., browse/Movies
        if (preg_match('#^browse/([^/]+)$#i', $path, $matches)) {
            $parentCategoryName = $matches[1];

            $blockedCategory = $this->checkMainCategoryPermission($user, $parentCategoryName);
            if ($blockedCategory) {
                return $this->abortCategoryDisabled($blockedCategory);
            }

            return $next($request);
        }

        // Movies category
        if ($this->matchesCategoryPath($path, 'Movies')) {
            if (! $user->hasDirectPermission('view movies')) {
                return $this->abortCategoryDisabled('Movies');
            }

            return $next($request);
        }

        // Console category
        if ($this->matchesCategoryPath($path, 'Console')) {
            if (! $user->hasDirectPermission('view console')) {
                return $this->abortCategoryDisabled('Console');
            }

            return $next($request);
        }

        // Books category
        if ($this->matchesCategoryPath($path, 'Books')) {
            if (! $user->hasDirectPermission('view books')) {
                return $this->abortCategoryDisabled('Books');
            }

            return $next($request);
        }

        // Audio category
        if ($this->matchesCategoryPath($path, 'Audio')) {
            if (! $user->hasDirectPermission('view audio')) {
                return $this->abortCategoryDisabled('Audio');
            }

            return $next($request);
        }

        // Adult (XXX) category, and the Adult releases list at /adult
        if ($this->matchesCategoryPath($path, 'XXX') || $this->matchesCategoryPath($path, 'adult')) {
            if (! $user->hasDirectPermission('view adult')) {
                return $this->abortCategoryDisabled('Adult');
            }

            return $next($request);
        }

        // PC/Games category
        if ($this->matchesCategoryPath($path, 'Games') || $this->matchesCategoryPath($path, 'PC')) {
            if (! $user->hasDirectPermission('view pc')) {
                return $this->abortCategoryDisabled('PC');
            }

            return $next($request);
        }

        // TV category
        if ($this->matchesCategoryPath($path, 'TV')
            || $this->matchesCategoryPath($path, 'myshows')) {
            if (! $user->hasDirectPermission('view tv')) {
                return $this->abortCategoryDisabled('TV');
            }

            return $next($request);
        }

        return $next($request);
    }

    /**
     * Check if the user has permission to view a main category.
     *
     * The route segment resolves through {@see BrowseRoot::fromRoute()}, so an
     * alias (music, adult, pc) gets exactly the check its canonical name gets.
     *
     * @return string|null The blocked category name, or null if allowed
     */
    protected function checkMainCategoryPermission(mixed $user, string $parentCategoryName): ?string
    {
        $root = BrowseRoot::fromRoute($parentCategoryName);
        $permission = $root?->permission();
        if ($root === null || $permission === null) {
            return null;
        }

        return $user->hasDirectPermission($permission) ? null : $root->hiddenName();
    }

    /**
     * Check if the user has excluded a specific subcategory.
     *
     * @return string|null The blocked "Root - Sub-category" name, or null if allowed
     */
    protected function checkSubcategoryExclusion(mixed $user, string $parentCategoryName, string $subcategoryName): ?string
    {
        $root = BrowseRoot::fromRoute($parentCategoryName);
        $rootId = $root?->categoryId();
        if ($root === null || $rootId === null) {
            return null;
        }

        // Get the subcategory
        $subcategory = Category::query()
            ->where('root_categories_id', $rootId)
            ->whereRaw('LOWER(title) = ?', [strtolower($subcategoryName)])
            ->first();

        if (! $subcategory) {
            return null;
        }

        // Check if this subcategory is in the user's exclusion list
        $isExcluded = $user->excludedCategories()
            ->where('categories_id', $subcategory->id)
            ->exists();

        if ($isExcluded) {
            return $root->hiddenName().' - '.$subcategory->title;
        }

        return null;
    }

    /**
     * Check if the path matches a category pattern (case-insensitive).
     */
    protected function matchesCategoryPath(string $path, string $category): bool
    {
        $lowerPath = strtolower($path);
        $lowerCategory = strtolower($category);

        // Match: Category, Category/*, browse/Category, browse/Category/*
        return $lowerPath === $lowerCategory
            || str_starts_with($lowerPath, $lowerCategory.'/')
            || $lowerPath === 'browse/'.$lowerCategory
            || str_starts_with($lowerPath, 'browse/'.$lowerCategory.'/');
    }

    /**
     * Abort with a category disabled response.
     */
    protected function abortCategoryDisabled(string $category): Response
    {
        return response()->view('errors.category-disabled', [
            'category' => $category,
        ], 403);
    }

    /**
     * Abort with a subcategory disabled response.
     */
    protected function abortSubcategoryDisabled(string $category): Response
    {
        return response()->view('errors.category-disabled', [
            'category' => $category,
            'isSubcategory' => true,
        ], 403);
    }
}
