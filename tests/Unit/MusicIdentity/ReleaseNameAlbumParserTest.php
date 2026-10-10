<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\DTO\ReleaseNameAlbum;
use App\Services\MusicIdentity\Matching\ReleaseNameAlbumParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Issue #1033: the parses of a release name, in the order the reference implementation gives them. */
final class ReleaseNameAlbumParserTest extends TestCase
{
    /**
     * @param  list<array{string, list<string>, int|null}>  $expected
     */
    #[Test]
    #[DataProvider('releaseNames')]
    public function a_release_name_parses_into_its_artist_title_variants_and_year(string $name, array $expected): void
    {
        $parses = array_map(
            static fn (ReleaseNameAlbum $album): array => [$album->artist, $album->titles, $album->year],
            (new ReleaseNameAlbumParser)->parse($name),
        );

        $this->assertSame($expected, $parses);
    }

    /** @return iterable<string, array{string, list<array{string, list<string>, int|null}>}> */
    public static function releaseNames(): iterable
    {
        yield 'a hyphen inside the title' => [
            'The Cranberries - Stars- The Best of 1992-2002 [FLAC]',
            [['The Cranberries', ['Stars- The Best of 1992-2002'], null]],
        ];
        yield 'a bracketed year and a format group' => [
            'Candy Dulfer - Crazy (2011)(flac)',
            [['Candy Dulfer', ['Crazy'], 2011]],
        ];
        yield 'a scene name whose title is a year' => [
            'Taylor_Swift-1989-2LP-24BIT-FLAC-2014-REETKEVER',
            [['Taylor Swift', ['1989'], 2014], ['Taylor Swift', ['2LP'], 1989]],
        ];
        yield 'a part counter before a quoted scene file name' => [
            '[002/112] "Rush-Fly_By_Night-LP-24BIT-FLAC-1975-REETKEVER.part001.rar"',
            [['Rush', ['Fly By Night'], 1975]],
        ];
        yield 'a wrapped name before a quoted track file' => [
            '(Neurosis - An Undying Love for a Burning World (2026)) [01/18] - "01 - We Are Torn Wide Open.flac"',
            [['Neurosis', ['An Undying Love for a Burning World'], 2026], ['01', ['We Are Torn Wide Open'], null]],
        ];
        yield 'artist-year-title with a catalogue group' => [
            'Pixies-1989-Doolittle [GAD 905 CD] [01/15] - "Pixies-1989-Doolittle [GAD 905 CD].nfo"',
            [['Pixies', ['Doolittle [GAD 905 CD]', 'Doolittle'], 1989]],
        ];
        yield 'a poster before the artist' => [
            'WhiteFang - Bon Jovi - 2003 - This Left Feels Right (FLAC)',
            [
                ['WhiteFang', ['Bon Jovi - 2003 - This Left Feels Right'], null],
                ['WhiteFang', ['Bon Jovi'], null],
                ['Bon Jovi', ['This Left Feels Right'], 2003],
            ],
        ];
        yield 'a repeated artist segment' => [
            '4 Non Blondes - 4 Non Blondes - Bigger, Better, Faster, More! (1992) [FLAC]',
            [['4 Non Blondes', ['Bigger, Better, Faster, More!'], 1992]],
        ];
        yield 'an edition group beside the year' => [
            'Everything But The Girl - Walking Wounded (2026 Deluxe Edition) (2026) FLAC',
            [['Everything But The Girl', ['Walking Wounded (2026 Deluxe Edition)', 'Walking Wounded'], 2026]],
        ];
        yield 'a remix group keeps the title whole' => [
            'Bunny X - Love Minus 80 (The Remixes)(Electronic) [2023] [FLAC]',
            [['Bunny X', ['Love Minus 80 (The Remixes)(Electronic)'], 2023]],
        ];
        yield 'a file suffix and a link' => [
            'The_Cure_-_Disintegration-3CD-2010-CMG.nzb http://nzb-dogz.com',
            [['The Cure', ['Disintegration'], 2010]],
        ];
        yield 'a bracketed date' => [
            'Miley Cyrus - Bass Persuades (2026-09-18) [FLAC]',
            [['Miley Cyrus', ['Bass Persuades'], 2026]],
        ];
        yield 'a first segment that is a code' => [
            'oz1978_004 - John Paul Young - Love Is In The Air.mp3',
            [
                ['oz1978_004', ['John Paul Young - Love Is In The Air'], null],
                ['oz1978_004', ['John Paul Young'], null],
            ],
        ];
        yield 'a number in the title' => [
            'Chicago - Chicago 16 (1982) FLAC',
            [['Chicago', ['Chicago 16'], 1982]],
        ];
        yield 'a hyphenated title word and a bitrate group' => [
            'Loudon Wainwright III - T-Shirt (1976)(320)',
            [['Loudon Wainwright III', ['T-Shirt'], 1976]],
        ];
        yield 'various artists' => [
            'VA-The_Ultimate_90s-5CD-2009-COS.part01.rar http://nzb-dogz.com',
            [['Various Artists', ['The Ultimate 90s'], 2009]],
        ];
        yield 'an unpaired quote' => [
            'Candye Kane - White Trash Girl (2005).part06.rar"',
            [['Candye Kane', ['White Trash Girl'], 2005]],
        ];
        yield 'no separator between artist and title' => [
            'Strokes New Abnormal 2020 [01/23] - "101 The Adults Are Talking.flac"',
            [],
        ];
        yield 'an obfuscated name' => ['6nrUSrcwHgGoYcrXIyeKP', []];
        yield 'a discography post' => ['Purple Yoda Posts: Primus Discography.part22.rar <23 vd 36>', []];
    }
}
