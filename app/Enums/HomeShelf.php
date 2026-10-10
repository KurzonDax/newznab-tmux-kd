<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The nine shelves of the home page (docs/proposals/home-redesign/SPEC.md 3 and 4), in their default
 * order. The value is the shelf's heading and the name stored in `users.view_prefs.home`.
 */
enum HomeShelf: string
{
    case Following = 'Following';
    case Tv = 'TV';
    case Movies = 'Movies';
    case Audio = 'Audio';
    case Books = 'Books';
    case Console = 'Console';
    case Pc = 'PC';
    case Adult = 'Adult';
    case Other = 'Other';

    /** @return list<self> the shelves ticked while a user has stored no choice */
    public static function defaultTicked(): array
    {
        return [self::Following, self::Tv, self::Movies, self::Audio, self::Books];
    }

    /** The section the shelf shows; null for Following (shows and films). */
    public function root(): ?BrowseRoot
    {
        return match ($this) {
            self::Following => null,
            self::Tv => BrowseRoot::Tv,
            self::Movies => BrowseRoot::Movies,
            self::Audio => BrowseRoot::Audio,
            self::Books => BrowseRoot::Books,
            self::Console => BrowseRoot::Console,
            self::Pc => BrowseRoot::Games,
            self::Adult => BrowseRoot::Adult,
            self::Other => BrowseRoot::Other,
        };
    }

    /**
     * The section's `releases.category_band`; null for Following. Other's categories are 10 and 20,
     * so its band is 0 (its root id, 1, is not a band).
     */
    public function band(): ?int
    {
        return match ($this) {
            self::Following => null,
            self::Other => 0,
            default => $this->root()?->categoryId(),
        };
    }

    /** The one-line description in the Shelves dialog, also the rail's accessible name. */
    public function description(): string
    {
        return match ($this) {
            self::Following => 'Your followed shows and films, the newest release first',
            self::Tv => 'Shows with new episodes in the last 24 hours',
            self::Movies => 'Films posted in the last 7 days',
            self::Audio => 'The newest albums',
            self::Books, self::Console, self::Pc => 'The newest releases',
            self::Adult => 'The newest releases, with their preview pictures',
            self::Other => 'The newest releases (Misc and Hashed)',
        };
    }

    public function seeAllLabel(): string
    {
        return $this === self::Following ? 'Manage Following' : 'All '.$this->value;
    }

    /** The path the See all link opens: the Following page or the section's list. */
    public function seeAllPath(): string
    {
        return match ($this) {
            self::Following => '/watchlist',
            self::Tv => '/tv',
            self::Movies => '/movies',
            self::Audio => '/audio',
            self::Books => '/books',
            self::Console => '/console',
            self::Pc => '/pc',
            self::Adult => '/adult',
            self::Other => '/browse/other',
        };
    }

    /** @return list<string> the tile kinds a panel of this shelf may be asked for */
    public function tileKinds(): array
    {
        return match ($this) {
            self::Following => ['show', 'film'],
            self::Tv => ['show'],
            self::Movies => ['film'],
            self::Audio => ['album'],
            self::Adult => ['pic'],
            default => ['rel'],
        };
    }
}
