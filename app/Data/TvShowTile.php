<?php

declare(strict_types=1);

namespace App\Data;

/** One tile on the TV shows wall: poster (or a title card), title, `Year · Genre, Genre`, `Language · Rating`. */
final readonly class TvShowTile
{
    public function __construct(
        public int $id,
        public string $title,
        public string $url,
        public ?string $poster,
        public string $line1,
        public string $line2,
    ) {}
}
