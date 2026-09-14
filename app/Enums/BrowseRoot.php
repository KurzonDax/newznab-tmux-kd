<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Category;

enum BrowseRoot: string
{
    case All = 'all';
    case Movies = 'movies';
    case Tv = 'tv';
    case Audio = 'audio';
    case Console = 'console';
    case Games = 'games';
    case Books = 'books';
    case Adult = 'xxx';
    case Other = 'other';

    public static function fromRoute(string $value): ?self
    {
        return match (strtolower($value)) {
            'music' => self::Audio,
            'adult' => self::Adult,
            'pc' => self::Games,
            default => self::tryFrom(strtolower($value)),
        };
    }

    public static function fromCategoryId(int $categoryId): self
    {
        $rootId = Category::rootCategoryFor($categoryId);
        foreach (self::cases() as $root) {
            if ($root->categoryId() === $rootId) {
                return $root;
            }
        }

        return self::All;
    }

    public function categoryId(): ?int
    {
        return match ($this) {
            self::All => null,
            self::Movies => Category::MOVIE_ROOT,
            self::Tv => Category::TV_ROOT,
            self::Audio => Category::MUSIC_ROOT,
            self::Console => Category::GAME_ROOT,
            self::Games => Category::PC_ROOT,
            self::Books => Category::BOOKS_ROOT,
            self::Adult => Category::XXX_ROOT,
            self::Other => Category::OTHER_ROOT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All releases',
            self::Tv => 'TV',
            self::Adult => 'Adult',
            self::Games => 'PC / Games',
            default => ucfirst($this->value),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::All => 'fas fa-list',
            self::Movies => 'fas fa-film',
            self::Tv => 'fas fa-tv',
            self::Audio => 'fas fa-music',
            self::Console, self::Games => 'fas fa-gamepad',
            self::Books => 'fas fa-book-open',
            self::Adult => 'fas fa-venus-mars',
            self::Other => 'fas fa-box',
        };
    }

    /** @return list<string> */
    public function views(): array
    {
        return match ($this) {
            self::All, self::Other => ['table'],
            self::Games => ['table', 'covers'],
            default => ['table', 'cards', 'covers'],
        };
    }

    /** @return list<string> */
    public function coverSizes(): array
    {
        return $this === self::Adult ? ['s', 'l'] : ['s', 'l', 'xl'];
    }
}
