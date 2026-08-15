<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use Closure;

class GroupObfuscatedRoutingPipe extends AbstractCategorizationPipe
{
    protected int $priority = 2;

    public function getName(): string
    {
        return 'GroupObfuscatedRouting';
    }

    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        $rootCategoryId = $passable->context->obfuscatedDefaultRootCategoryId;

        if ($passable->lockedToMisc ||
            ! $passable->context->routeObfuscatedNames ||
            $rootCategoryId === null ||
            ! $this->isRoutableObfuscatedMatch($passable->bestResult->matchedBy)) {
            return $next($passable);
        }

        $categoryId = Category::otherForRootCategory($rootCategoryId);

        if ($categoryId === null) {
            return $next($passable);
        }

        $result = new CategorizationResult(
            $categoryId,
            0.7,
            'group_obfuscated_default_root',
            [
                'root_category_id' => $rootCategoryId,
                'misc_match' => $passable->bestResult->matchedBy,
            ],
        );

        $passable->bestResult = $result;

        if ($passable->debug) {
            $passable->allResults[$this->getName()] = [
                'category_id' => $result->categoryId,
                'confidence' => $result->confidence,
                'matched_by' => $result->matchedBy,
            ];
        }

        return $next($passable);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->noMatch();
    }

    private function isRoutableObfuscatedMatch(string $matchedBy): bool
    {
        return str_starts_with($matchedBy, 'obfuscated_')
            || str_starts_with($matchedBy, 'gibberish_');
    }
}
