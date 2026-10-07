<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\ReleaseContext;

/**
 * Pipe for Book content categorization.
 */
class BookPipe extends AbstractCategorizationPipe
{
    protected int $priority = 45;

    private BookCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new BookCategorizer;
    }

    public function getName(): string
    {
        return 'Book';
    }

    protected function shouldSkip(ReleaseContext $context): bool
    {
        return $this->categorizer->shouldSkip($context);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    /**
     * The shape-only magazine rules yield to an earlier pipe that recognised
     * the name itself, even at a lower confidence ("WWE Raw - September 28
     * 2026" is sport, "Hed Kandi - Summer 2024" is a DJ mix). Group-name
     * hints, the Movie pipe's bare-year guess and misc results are not
     * recognitions of the name, so they do not suppress.
     */
    protected function suppressionReason(
        CategorizationResult $result,
        CategorizationResult $bestResult,
    ): ?string {
        if (! in_array($result->matchedBy, ['magazine_word', 'magazine_dated_title', 'magazine_title_dated'], true)) {
            return null;
        }
        if (! $bestResult->isSuccessful()
            || $bestResult->categoryId === Category::OTHER_MISC
            || str_starts_with($bestResult->matchedBy, 'group_name_')
            || $bestResult->matchedBy === 'year_only_movie') {
            return null;
        }

        return 'earlier_content_match';
    }
}
