<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use Closure;

/**
 * Routes every release from a configured group to one root category.
 *
 * The switch is absolute by design: a group that carries a single kind of
 * content (an adult group, say) gets everything filed under its root no matter
 * what the name-based pipeline concluded. Two results survive it — a more
 * specific category that already belongs to the forced root, and a
 * hashed/obfuscated name, which stays on the obfuscated-routing path.
 */
class GroupForcedRootPipe extends AbstractCategorizationPipe
{
    protected int $priority = 99;

    public function getName(): string
    {
        return 'GroupForcedRoot';
    }

    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        $rootCategoryId = $passable->context->forcedRootCategoryId;

        if ($rootCategoryId === null ||
            $passable->lockedToMisc ||
            $passable->bestResult->categoryId === Category::OTHER_HASHED ||
            Category::rootCategoryFor($passable->bestResult->categoryId) === $rootCategoryId) {
            return $next($passable);
        }

        $categoryId = Category::otherForRootCategory($rootCategoryId);

        if ($categoryId === null) {
            return $next($passable);
        }

        $result = new CategorizationResult(
            $categoryId,
            0.95,
            'group_forced_root',
            [
                'root_category_id' => $rootCategoryId,
                'organic_category_id' => $passable->bestResult->categoryId,
                'organic_match' => $passable->bestResult->matchedBy,
            ],
        );

        if ($passable->debug) {
            $passable->allResults[$this->getName()] = [
                'category_id' => $result->categoryId,
                'confidence' => $result->confidence,
                'matched_by' => $result->matchedBy,
                'overrode' => [
                    'category_id' => $passable->bestResult->categoryId,
                    'matched_by' => $passable->bestResult->matchedBy,
                ],
            ];
        }

        $passable->bestResult = $result;

        return $next($passable);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->noMatch();
    }
}
