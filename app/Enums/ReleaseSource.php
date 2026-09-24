<?php

declare(strict_types=1);

namespace App\Enums;

/** The stored `releases.source` value, read from the name. */
enum ReleaseSource: int
{
    case Unknown = 0;
    case Web = 1;
    case BluRay = 2;
    case Dvd = 3;
    case Hdtv = 4;
    /** A Blu-ray remux: the Blu-ray filter matches it, the UI shows "Remux". */
    case Remux = 5;

    /** @return list<self> The known sources, strongest name evidence first. */
    public static function precedence(): array
    {
        return [self::Remux, self::Web, self::BluRay, self::Dvd, self::Hdtv];
    }

    /** Regex alternation of the name tokens that declare this source. */
    public function nameTokens(): string
    {
        return match ($this) {
            self::Unknown => '',
            self::Remux => 'REMUX|BDRemux',
            self::Web => 'WEB[ ._-]?DL|WEBRip|WEB',
            self::BluRay => 'BluRay|Blu-Ray|BDRip|BRRip',
            self::Dvd => 'DVDRip|DVD[59]|DVD',
            self::Hdtv => 'HDTV|PDTV|SDTV|DSR|TVRip',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Web => 'WEB',
            self::BluRay => 'Blu-ray',
            self::Dvd => 'DVD',
            self::Hdtv => 'HDTV',
            self::Remux => 'Remux',
        };
    }
}
