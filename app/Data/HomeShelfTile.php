<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One tile on a home shelf's rail (docs/proposals/home-redesign/SPEC.md 3.2): a show or film poster
 * tile, a square album tile, a release card or the Adult picture tile. Every tile is a button that
 * opens its panel under the rail.
 */
final readonly class HomeShelfTile
{
    /**
     * @param  'show'|'film'|'album'|'rel'|'pic'  $kind
     * @param  int  $id  the show's videos id, the film's movieinfo id, or the release id (an album tile: its newest release)
     * @param  string  $title  the show, film ("Title (Year)" on Movies), album or release name
     * @param  string  $what  the meta line under the title
     * @param  string|null  $art  the poster, or the Adult picture; null for the title card or "No picture"
     * @param  string  $label  the film's year on its title card, the album's performer, the release's sub-category
     * @param  string  $badge  "N new" on a Following tile with releases since the last visit
     * @param  bool  $faded  a Following tile with nothing new
     */
    public function __construct(
        public string $kind,
        public int $id,
        public string $title,
        public string $what,
        public ?string $art = null,
        public string $label = '',
        public string $badge = '',
        public bool $faded = false,
    ) {}
}
