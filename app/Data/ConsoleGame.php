<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The game of a Console release with a game, as its release page shows it
 * (docs/proposals/books-console-pc-redesign/SPEC.md 5B; DATA-CONTRACT.md 4.3): the header's cover,
 * game line, summary, storyline, tags, info lines and outside links, and the number of the game's
 * releases the viewer may see. A missing value is '' or null (an empty list for the lists), and
 * the page leaves its part out.
 */
final readonly class ConsoleGame
{
    /**
     * @param  string  $year  the first four digits of the release date; '' without one
     * @param  string  $releaseDate  the stored release date as `Y-m-d`; '' without one
     * @param  string  $ageRating  `consoleinfo.esrb`: the bare ESRB code, else `PEGI <rating>`; '' without one
     * @param  string  $igdbUrl  the game's IGDB page; '' unless `asin` holds an IGDB game id and `url` is a stored web address
     * @param  string  $website  the game's official site; '' unless a web address (http or https) is stored
     * @param  string|null  $cover  the cover URL; null unless a cover is stored and its file exists
     * @param  array<int, string>  $genres  genres.id => title, in `console_genres.position` order, without the Unknown genre
     * @param  list<string>  $developers  in `console_companies.position` order
     * @param  list<string>  $publishers  in `console_companies.position` order
     * @param  list<string>  $gameModes  in `console_game_modes.position` order
     * @param  list<string>  $perspectives  in `console_player_perspectives.position` order
     * @param  int  $releases  the game's Console releases the viewer may see
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $year,
        public string $releaseDate,
        public string $summary,
        public string $storyline,
        public ?int $criticScore,
        public ?int $userScore,
        public string $ageRating,
        public string $igdbUrl,
        public string $website,
        public ?string $cover,
        public array $genres,
        public array $developers,
        public array $publishers,
        public array $gameModes,
        public array $perspectives,
        public int $releases,
    ) {}

    /**
     * The outlined tags after the genres: Critic score, User score and the age rating, each left
     * out when missing. A PEGI rating is stored as `PEGI 12` and shows as it is; any other value is
     * an ESRB code (`ESRB M`).
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $age = $this->ageRating === '' ? '' : (str_starts_with($this->ageRating, 'PEGI ') ? $this->ageRating : 'ESRB '.$this->ageRating);

        return array_values(array_filter([
            $this->criticScore === null ? '' : 'Critic score '.$this->criticScore,
            $this->userScore === null ? '' : 'User score '.$this->userScore,
            $age,
        ], static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * The info lines, in order, each left out when its value is missing; Released is the full
     * date (`Mar 5, 2015`), a calendar date never shifted to the viewer's time zone.
     *
     * @return array<string, string> label => value
     */
    public function infoLines(): array
    {
        return array_filter([
            'Developed by' => implode(', ', $this->developers),
            'Published by' => implode(', ', $this->publishers),
            'Released' => $this->releaseDate === '' ? '' : CarbonImmutable::createFromFormat('!Y-m-d', $this->releaseDate, 'UTC')->format('M j, Y'),
            'Game modes' => implode(', ', $this->gameModes),
            'Perspective' => implode(', ', $this->perspectives),
        ], static fn (string $value): bool => $value !== '');
    }
}
