<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\MusicCategorizer;
use App\Services\Categorization\ReleaseContext;

/**
 * Pipe for Music content categorization.
 */
class MusicPipe extends AbstractCategorizationPipe
{
    protected int $priority = 40;

    private MusicCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new MusicCategorizer;
    }

    public function getName(): string
    {
        return 'Music';
    }

    protected function shouldSkip(ReleaseContext $context): bool
    {
        return $this->categorizer->shouldSkip($context);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    protected function suppressionReason(
        CategorizationResult $result,
        CategorizationResult $bestResult,
    ): ?string {
        if ($bestResult->categoryId < Category::MOVIE_ROOT ||
            $bestResult->categoryId >= Category::MUSIC_ROOT ||
            $bestResult->confidence < 0.8) {
            return null;
        }

        foreach (['music_video', 'music_album', 'music_other', 'mp3'] as $matchedByPrefix) {
            if (str_starts_with($result->matchedBy, $matchedByPrefix)) {
                return 'movie_precedence';
            }
        }

        return null;
    }
}
