<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;

/**
 * Which categories own each metadata link. A category move clears a link only when the old
 * category owns it and the new one does not.
 */
final class CategoryLinkOwnership
{
    /** @var array<string, list<array{int, int}>> link => the category ranges that own it */
    private const array OWNERS = [
        'movieinfo_id' => [[Category::MOVIE_ROOT, Category::MOVIE_OTHER]],
        'imdbid' => [[Category::MOVIE_ROOT, Category::MOVIE_OTHER]],
        'videos_id' => [[Category::TV_ROOT, Category::TV_ANIME - 1], [Category::TV_ANIME + 1, Category::TV_OTHER]],
        'tv_episodes_id' => [[Category::TV_ROOT, Category::TV_ANIME - 1], [Category::TV_ANIME + 1, Category::TV_OTHER]],
        'anidbid' => [[Category::TV_ANIME, Category::TV_ANIME]],
        'musicinfo_id' => [[Category::MUSIC_ROOT, Category::MUSIC_OTHER]],
        'consoleinfo_id' => [[Category::GAME_ROOT, Category::GAME_OTHER]],
        'gamesinfo_id' => [[Category::PC_GAMES, Category::PC_GAMES]],
        'bookinfo_id' => [[Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN], [Category::MUSIC_AUDIOBOOK, Category::MUSIC_AUDIOBOOK]],
    ];

    /** The value a cleared link takes. */
    private const array CLEARED = [
        'movieinfo_id' => null,
        'imdbid' => null,
        'videos_id' => 0,
        'tv_episodes_id' => 0,
        'anidbid' => null,
        'musicinfo_id' => null,
        'consoleinfo_id' => null,
        'gamesinfo_id' => 0,
        'bookinfo_id' => null,
    ];

    /**
     * The links a move from one category to another clears, with the value each takes.
     *
     * @return array<string, int|null>
     */
    public static function clearedLinks(int $fromCategoryId, int $toCategoryId): array
    {
        $cleared = [];
        foreach (self::OWNERS as $link => $ranges) {
            if (self::within($fromCategoryId, $ranges) && ! self::within($toCategoryId, $ranges)) {
                $cleared[$link] = self::CLEARED[$link];
            }
        }

        return $cleared;
    }

    /**
     * For a move of many releases to one category: per link the new category does not own, the
     * SQL condition on the old `categories_id` under which the link is cleared, and its value.
     *
     * @return array<string, array{string, int|null}>
     */
    public static function clearedLinksByOldCategory(int $toCategoryId): array
    {
        $cleared = [];
        foreach (self::OWNERS as $link => $ranges) {
            if (! self::within($toCategoryId, $ranges)) {
                $cleared[$link] = [
                    implode(' OR ', array_map(static fn (array $range): string => "categories_id BETWEEN {$range[0]} AND {$range[1]}", $ranges)),
                    self::CLEARED[$link],
                ];
            }
        }

        return $cleared;
    }

    /** @param list<array{int, int}> $ranges */
    private static function within(int $categoryId, array $ranges): bool
    {
        foreach ($ranges as [$first, $last]) {
            if ($categoryId >= $first && $categoryId <= $last) {
                return true;
            }
        }

        return false;
    }
}
