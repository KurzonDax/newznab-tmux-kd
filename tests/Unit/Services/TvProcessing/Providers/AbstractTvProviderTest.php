<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TvProcessing\Providers;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

class AbstractTvProviderTest extends ImdbScraperTestCase
{
    /**
     * @param  array{cleanname: string, season: int|string, episode: int, airdate: string}  $expected
     */
    #[Test]
    #[DataProvider('episodeFormats')]
    public function it_parses_episode_formats_without_leaking_boundaries_into_titles(string $releaseName, array $expected): void
    {
        $showInfo = (new LocalDbProvider)->parseInfo($releaseName);

        $this->assertIsArray($showInfo);
        $this->assertSame($expected['cleanname'], $showInfo['cleanname']);
        $this->assertSame($expected['season'], $showInfo['season']);
        $this->assertSame($expected['episode'], $showInfo['episode']);
        $this->assertSame($expected['airdate'], $showInfo['airdate']);
    }

    #[Test]
    public function it_parses_a_four_digit_season_without_truncating_it(): void
    {
        $info = (new LocalDbProvider)->parseInfo(
            'Dangerous.Encounters.(2005).-.S2010E05.-.Cannibal.Squid.[SDTV][MP3.2.0][XviD]-KAFFEREP',
        );

        $this->assertIsArray($info);
        $this->assertSame('Dangerous Encounters', $info['name']);
        $this->assertSame(2010, $info['season']);
        $this->assertSame(5, $info['episode']);
    }

    #[Test]
    public function it_skips_imdb_updates_when_the_new_value_is_empty(): void
    {
        Cache::flush();

        $sql = $this->buildUpdateQuery([
            'country' => '',
            'tvdb' => 394553,
            'trakt' => 0,
            'tvrage' => 0,
            'tvmaze' => 0,
            'imdb' => '',
            'tmdb' => 117643,
            'summary' => 'Summary',
            'publisher' => 'Nine Network',
            'localzone' => "''",
            'aliases' => '',
        ]);

        $this->assertStringContainsString('v.imdb = v.imdb', $sql);
        $this->assertStringNotContainsString('v.imdb = IF(v.imdb = 0, , v.imdb)', $sql);
        $this->assertStringContainsString("tvi.localzone = IF(tvi.localzone = '', '', tvi.localzone)", $sql);
    }

    #[Test]
    public function it_normalizes_tt_prefixed_imdb_ids_in_update_queries(): void
    {
        Cache::flush();

        $sql = $this->buildUpdateQuery([
            'country' => 'AU',
            'tvdb' => 394553,
            'trakt' => 0,
            'tvrage' => 0,
            'tvmaze' => 0,
            'imdb' => 'tt1176432',
            'tmdb' => 117643,
            'summary' => 'Summary',
            'publisher' => 'Nine Network',
            'localzone' => '',
            'aliases' => '',
        ]);

        $this->assertStringContainsString("v.imdb = IF(v.imdb IN ('', '0'), '1176432', v.imdb)", $sql);
    }

    #[Test]
    public function title_year_names_have_an_explicit_no_episode_marker(): void
    {
        foreach ([
            'Sterling Point (2026)' => 'Sterling Point (2026)',
            'Sterling.Point.2026' => 'Sterling Point (2026)',
            'Years and Years (2019)' => 'Years and Years (2019)',
            'The.Mind.Behind.Power.2024.1.mkv' => 'The Mind Behind Power (2024)',
        ] as $name => $cleanname) {
            $info = (new LocalDbProvider)->parseInfo($name);
            $this->assertIsArray($info);
            $this->assertSame($cleanname, $info['cleanname']);
            $this->assertSame('none', $info['episode']);
        }
        $this->assertFalse((new LocalDbProvider)->parseInfo('Con.Air.1997.1080p.BluRay.x264-GRP'));
    }

    #[Test]
    public function existing_episode_and_season_formats_keep_their_parsed_shape(): void
    {
        $provider = new LocalDbProvider;
        foreach ([
            'Show.S01E02' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 1, 'episode' => 2, 'episode_title' => '', 'airdate' => '', 'numbered_title' => ''],
            'Show.S01' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 1, 'episode' => 'all', 'episode_title' => '', 'airdate' => '', 'numbered_title' => ''],
            'Show.2024.05.01' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 0, 'episode' => 0, 'episode_title' => '', 'airdate' => '2024-05-01', 'numbered_title' => ''],
            'Show.S03E05.Episode.Title' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 3, 'episode' => 5, 'episode_title' => '', 'airdate' => '', 'numbered_title' => ''],
        ] as $name => $expected) {
            $this->assertEquals($expected, $provider->parseInfo($name), $name);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, int, int|string}>
     */
    public static function numberedTitles(): iterable
    {
        yield 'year before the episode tag' => ['Sealab.2021.S01E10.1080p.WEB.H264-OUTPOST31.mkv', 'Sealab 2021', 'Sealab', 'Sealab (2021)', 1, 10, ''];
        yield 'premiere year of a same-named show' => ['The.Flash.2014.S01E01.1080p.BluRay.x264-GRP', 'The Flash 2014', 'The Flash', 'The Flash (2014)', 1, 1, ''];
        yield 'title number then premiere year' => ['Chief.Detective.1958.2024.S01E01.Man.From.a.Small.City.1080p.DSNP.WEB-DL.AAC2.0.H.264-MARK', 'Chief Detective 1958', 'Chief Detective', 'Chief Detective (1958)', 1, 1, 'Man From a Small City'];
        yield 'second title-number form' => ['Reply.1988.2015.S01E01.1080p.DSNP.WEB-DL.H.264.AAC-UBWEB', 'Reply 1988', 'Reply', 'Reply (1988)', 1, 1, ''];
        yield 'numbers in the name' => ['Ranma.1.2.2024.S03E02.1080p.WEB.H264-SUGOI', 'Ranma 1 2 2024', 'Ranma 1 2', 'Ranma 1 (2024)', 3, 2, ''];
        yield 'season pack' => ['Sealab.2021.S04.iNTERNAL.DVDRip.XviD-VCDVaULT', 'Sealab 2021', 'Sealab', 'Sealab (2021)', 4, 'all', ''];
        yield 'NxNN episode tag' => ['Sealab.2021.1x10.DVDRip.XviD-GRP', 'Sealab 2021', 'Sealab', 'Sealab (2021)', 1, 10, ''];
        yield 'bare episode tag' => ['Armed.Reaction.2021.E01.1080p.HDTV.DD5.1.x264-KMX', 'Armed Reaction 2021', 'Armed Reaction', 'Armed Reaction (2021)', 1, 1, ''];
        yield 'title number of a whole-word show' => ['Galactica.1980.S01E01.DVDRip', 'Galactica 1980', 'Galactica', 'Galactica (1980)', 1, 1, ''];
        yield 'title number after two words' => ['Prime.Suspect.1973.S01E01.720p', 'Prime Suspect 1973', 'Prime Suspect', 'Prime Suspect (1973)', 1, 1, ''];
        yield 'no year' => ['The.Flash.S04E01.1080p.BluRay.x264-GRP', '', 'The Flash', 'The Flash', 4, 1, ''];
        yield 'year after the episode tag' => ['The.Mentalist.S01E01.2008.1080p.Amazon.WEB-DL.AVC.DDP.5.1-4X4RYA-DBTV', '', 'The Mentalist', 'The Mentalist (2008)', 1, 1, ''];
        yield 'date after the episode tag' => ['Saturday.Night.Live.S52E04.Inde.Navarrette.October.31.2026.2160p.PCOK.WEB-DL.DD+5.1.Atmos.HDR.H.265-PlayWEB', '', 'Saturday Night Live', 'Saturday Night Live', 52, 4, 'Inde Navarrette October 31'];
        yield 'year then a year-numbered season' => ['Show.2009.Part1.720p', '', 'Show', 'Show (2009)', '2009', 1, ''];
        yield 'part tag' => ['Show.2010.Part.1.720p', '', 'Show', 'Show (2010)', 1, 1, ''];
    }

    #[Test]
    #[DataProvider('numberedTitles')]
    public function a_year_before_the_episode_tag_gives_the_release_a_numbered_title(
        string $releaseName,
        string $numberedTitle,
        string $name,
        string $cleanname,
        int|string $season,
        int|string $episode,
        string $episodeTitle,
    ): void {
        $info = (new LocalDbProvider)->parseInfo($releaseName);

        $this->assertIsArray($info);
        $this->assertSame($numberedTitle, $info['numbered_title']);
        $this->assertSame($name, $info['name']);
        $this->assertSame($cleanname, $info['cleanname']);
        $this->assertSame($season, $info['season']);
        $this->assertSame($episode, $info['episode']);
        $this->assertSame($episodeTitle, $info['episode_title']);
        $this->assertSame('', $info['airdate']);
    }

    #[Test]
    public function names_without_a_season_or_episode_tag_have_no_numbered_title(): void
    {
        $provider = new LocalDbProvider;

        $titleYear = $provider->parseInfo('Sterling.Point.2026');
        $this->assertIsArray($titleYear);
        $this->assertSame('', $titleYear['numbered_title']);
        $this->assertSame('Sterling Point (2026)', $titleYear['cleanname']);

        $dated = $provider->parseInfo('Show.2010.2011.01.05.720p');
        $this->assertIsArray($dated);
        $this->assertSame('', $dated['numbered_title']);
        $this->assertSame('2011-01-05', $dated['airdate']);
    }

    #[Test]
    public function a_trailing_year_does_not_replace_existing_episode_evidence(): void
    {
        foreach (['Show.01.05.2024', 'Show.Season.1.2026', 'Show_S01E02_2026'] as $name) {
            $info = (new LocalDbProvider)->parseInfo($name);
            $this->assertIsArray($info);
            $this->assertNotSame('none', $info['episode']);
        }
    }

    #[Test]
    #[DataProvider('datesAfterTheEpisodeMarker')]
    public function a_date_after_the_episode_marker_is_not_the_show_year(string $releaseName, string $cleanname, int $season, int $episode): void
    {
        $info = (new LocalDbProvider)->parseInfo($releaseName);

        $this->assertIsArray($info);
        $this->assertSame($cleanname, $info['cleanname']);
        $this->assertSame($season, $info['season']);
        $this->assertSame($episode, $info['episode']);
        $this->assertSame('', $info['airdate']);
    }

    #[Test]
    #[DataProvider('yearsThatStayTheShowYear')]
    public function a_year_outside_an_excluded_date_stays_the_show_year(string $releaseName, string $cleanname): void
    {
        $info = (new LocalDbProvider)->parseInfo($releaseName);

        $this->assertIsArray($info);
        $this->assertSame($cleanname, $info['cleanname']);
    }

    #[Test]
    public function a_bracketed_tag_followed_by_a_dot_is_removed_from_the_name(): void
    {
        $info = (new LocalDbProvider)->parseInfo(
            '[求婚大作战].Operation.Love.2007.S01E02.1080p.friDay.WEB-DL.H264.AAC-UBWEB.nfo',
        );

        $this->assertIsArray($info);
        $this->assertSame('Operation Love', $info['name']);
        $this->assertSame(1, $info['season']);
        $this->assertSame(2, $info['episode']);
    }

    #[Test]
    public function a_space_separated_name_loses_its_host_name_prefix(): void
    {
        $info = (new LocalDbProvider)->parseInfo('MoviezAddiction.Me | Sweet Home S01E01');

        $this->assertIsArray($info);
        $this->assertSame('Sweet Home', $info['cleanname']);
    }

    /**
     * @param  array<string, int|string>  $expected
     */
    #[Test]
    #[DataProvider('namesWithALaterPipe')]
    public function a_pipe_after_the_episode_marker_leaves_the_show_name_alone(string $releaseName, array $expected): void
    {
        $this->assertSame($expected, (new LocalDbProvider)->parseInfo($releaseName));
    }

    #[Test]
    public function a_name_with_invalid_utf8_is_not_parsed_and_raises_no_warning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $info = (new LocalDbProvider)->parseInfo("Caf\xE9.Show.S01E02.720p.HDTV.x264-GRP");
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($info);
        $this->assertSame([], $warnings);
    }

    #[Test]
    #[DataProvider('tagFreeNames')]
    public function the_tag_free_parse_drops_only_a_leading_group_tag(string $releaseName, ?string $tagFreeCleanname): void
    {
        $provider = new LocalDbProvider;
        $fullParse = $provider->parseInfo($releaseName);
        $this->assertIsArray($fullParse);

        $tagFreeParse = $provider->tagFreeParse($releaseName, $fullParse);

        $this->assertSame($tagFreeCleanname, $tagFreeParse === null ? null : $tagFreeParse['cleanname']);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function tagFreeNames(): array
    {
        return [
            'lower-case tag' => ['aaf-dexters.laboratory.s02e25', 'dexters laboratory'],
            'title-cased tag' => ['Dim-Highlander.The.Raven.S01e03.Bloodlines.Dvdrip.Xvid', 'Highlander The Raven'],
            'tag with digits' => ['tv4a-monk.s03e12-1080p.mkv', 'monk'],
            'eight-character tag' => ['abcdefgh-monk.s03e12', 'monk'],
            'season-shaped name keeps its season' => ['Rgl-sop.s2 DVDRip XviD.e02', 'sop'],
            'numbered title' => ['aaf-sealab.2021.s01e10', 'sealab (2021)'],
            'no dash' => ['Monk.S03E12.720p', null],
            'one-character tag' => ['a-monk.s03e12', null],
            'nine-character tag' => ['abcdefghi-monk.s03e12', null],
            'dash before a non-alphanumeric' => ['aaf-.monk.s03e12', null],
            'episode token as the tag' => ['S01E01-Monk.S03E12', null],
            'nothing parseable without the tag' => ['wat-s08e20-seinfeld', null],
        ];
    }

    private function makeProvider(): TraktProvider
    {
        return new TraktProvider;
    }

    /**
     * @param  array<string, mixed>  $show
     */
    private function buildUpdateQuery(array $show): string
    {
        $provider = $this->makeProvider();
        $method = new \ReflectionMethod($provider, 'buildUpdateQuery');

        return $method->invoke($provider, 123, $show);
    }

    /**
     * @return array<string, array{string, array<string, int|string>}>
     */
    public static function namesWithALaterPipe(): array
    {
        return [
            'space-separated name' => [
                'Altered Carbon S01E09 (2018) 1080p 10bit WEBRip | RZeroX ~ R3FL3X x265 DDP5.1 5.1 English ESub',
                ['name' => 'Altered Carbon', 'country' => 'CA', 'cleanname' => 'Altered Carbon (2018)', 'season' => 1, 'episode' => 9, 'episode_title' => '', 'airdate' => '', 'numbered_title' => ''],
            ],
            'dotted name' => [
                'Altered.Carbon.S01E09.1080p.WEB | RZeroX',
                ['name' => 'Altered Carbon', 'country' => 'CA', 'cleanname' => 'Altered Carbon', 'season' => 1, 'episode' => 9, 'episode_title' => '', 'airdate' => '', 'numbered_title' => ''],
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function datesAfterTheEpisodeMarker(): array
    {
        return [
            'month name, day, year' => [
                'Saturday.Night.Live.S52E04.Inde.Navarrette.October.31.2026.2160p.PCOK.WEB-DL.DD+5.1.Atmos.HDR.H.265-PlayWEB',
                'Saturday Night Live', 52, 4,
            ],
            'year-month-day with dots' => [
                'The.Young.And.The.Restless.S54E029.2026.11.17.1080p.AMZN.WEB-DL.DDP2.0.H.264-Kitsune',
                'The Young And The Restless', 54, 29,
            ],
            'weekday, month name, day, year' => [
                'Days.of.our.Lives.S62E043.Wednesday.October.21.2026.1080p.PCOK.WEB-DL.DDP2.0.H.264-Kitsune.mkv',
                'Days of our Lives', 62, 43,
            ],
            'day, abbreviated month, year' => [
                'A Place in the Sun S18E114 Wed 18 Nov 2026: Andalucía, Spain',
                'A Place in the Sun', 18, 114,
            ],
            'ordinal day, month name, year' => [
                'The Xtra Factor S06E11 (10th October 2009)[WS PDTV(Xvid)] Tiggzz',
                'The Xtra Factor', 6, 11,
            ],
            'Portuguese day, month name, year' => [
                'Show.S02E05.17.de.novembro.de.2026.1080p.WEB-DL.x264-GRP',
                'Show', 2, 5,
            ],
            'space-separated year-month-day' => [
                'The Young and the Restless S53E165 2026 06 03 1080p AMZN WEB-DL DDP2 0 H 264-Kitsune',
                'The Young and the Restless', 53, 165,
            ],
            'space-separated year-month-day before an episode title' => [
                'The Daily Show S31E042 2026 04 01 Dr Mary Claire Haver Host Desi Lydic 1080p AMZN WEB-DL DDP2 0 H 264-Kitsune',
                'The Daily Show', 31, 42,
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function yearsThatStayTheShowYear(): array
    {
        return [
            'year before the marker' => ['Doctor.Who.2005.S01E01.1080p.BluRay.x264-GRP', 'Doctor Who (2005)'],
            'another year before the marker' => [
                'Dirty.Laundry.2023.S01E08.1080p.HMAX.WEB-DL.DDP2.0.H.265.DUAL-SiGLA',
                'Dirty Laundry (2023)',
            ],
            'first year before the marker' => ['WWE NXT 2026 S20E50 17 de novembro de 2026', 'WWE NXT (2026)'],
            'title numbers before the marker' => ['Ranma.1.2.2024.S03E02.1080p.WEB.H264-SUGOI', 'Ranma 1 (2024)'],
            'dashed title numbers before the marker' => [
                'State.of.Siege.26-11.2020.S01E02.1080p.ZEE5.WEB-DL.AAC.2.0-EMB',
                'State of Siege (2020)',
            ],
            'bare year after the marker' => [
                'The.Mentalist.S01E01.2008.1080p.Amazon.WEB-DL.AVC.DDP.5.1-4X4RYA-DBTV',
                'The Mentalist (2008)',
            ],
            'year-month without a day' => [
                'Star.Trek.Enterprise.S02E02.Carbon.Creek.2001-02.1080p.Bluray.DTSMA.5.1.x264-GRP',
                'Star Trek Enterprise (2001)',
            ],
            'month name and year after the episode number' => [
                'Show.S02E09.April.2020.1080p.NF.WEB-DL.DDP5.1.x264-GRP',
                'Show (2020)',
            ],
            'audio channels before a year' => ['Show.S02E05.DDP5.1.2026.1080p', 'Show (2026)'],
            'bare year before a later date' => [
                'Show.S02E05.2019.Capitulo.de.08.05.2023.1080p',
                'Show (2019)',
            ],
            'season 1 day-month-year' => [
                'La.na.Minha.Terra.S01E09.Capitulo.de.18.11.2026.1080p.GLBO.WEB-DL.DDP5.1.Atmos.H.264-SiGLA',
                'La na Minha Terra (2026)',
            ],
            'season 1 year-month-day' => [
                'Iron.Family.S01E01.2024-09-28.1080p.NF.WEB-DL.AAC2.0.x.264-Saon',
                'Iron Family (2024)',
            ],
            'another season 1 year-month-day' => [
                'Doubt.S01E01.2024-10-11.1080p.NF.WEB-DL.AAC2.0.H.264-Spidey',
                'Doubt (2024)',
            ],
        ];
    }

    /**
     * @return array<string, array{string, array{cleanname: string, season: int|string, episode: int, airdate: string}}>
     */
    public static function episodeFormats(): array
    {
        return [
            'zero-padded EP token' => [
                'Juui.Dolittle.EP06.1080p.AMZN.WEB-DL.DDP2.0.H.264-MagicStar',
                ['cleanname' => 'Juui Dolittle', 'season' => 1, 'episode' => 6, 'airdate' => ''],
            ],
            'single-digit EP token and episode title' => [
                'BBC.Adventures.in.Architecture.EP1.Beauty.720p.HDTV.x264',
                ['cleanname' => 'BBC Adventures in Architecture', 'season' => 1, 'episode' => 1, 'airdate' => ''],
            ],
            'non-episode word starting with EP' => [
                'World.Of.EPCOT.S01E02.1080p.WEB-DL.x264',
                ['cleanname' => 'World Of EPCOT', 'season' => 1, 'episode' => 2, 'airdate' => ''],
            ],
            'season and episode' => [
                'Example.Show.S02E03.1080p.WEB-DL.x264',
                ['cleanname' => 'Example Show', 'season' => 2, 'episode' => 3, 'airdate' => ''],
            ],
            'dotted acronym before the next title word' => [
                'L.A.Law.S08E22.Finish.Line.1080p.DSNP.WEBRip.10bit.EAC3.5.1.X265-IVy',
                ['cleanname' => 'LA Law', 'season' => 8, 'episode' => 22, 'airdate' => ''],
            ],
            'dotted acronym without a following title word' => [
                'S.W.A.T.S08E22.1080p.WEB-DL.x264',
                ['cleanname' => 'SWAT', 'season' => 8, 'episode' => 22, 'airdate' => ''],
            ],
            'episode word' => [
                'Chernobyl.Episode.S01E03.1080p.WEB-DL.x264',
                ['cleanname' => 'Chernobyl', 'season' => 1, 'episode' => 3, 'airdate' => ''],
            ],
            'x episode' => ['Example.Show.1x05.HDTV', ['cleanname' => 'Example Show', 'season' => 1, 'episode' => 5, 'airdate' => '']],
            'year season without S' => ['Example.Show.2019.05.HDTV', ['cleanname' => 'Example Show (2019)', 'season' => '2019', 'episode' => 5, 'airdate' => '']],
            'dotted year season' => ['Example.Show.S2010.E05.HDTV', ['cleanname' => 'Example Show', 'season' => 2010, 'episode' => 5, 'airdate' => '']],
            'airdate' => [
                'Example.Show.2024.01.15.720p.HDTV.x264',
                ['cleanname' => 'Example Show', 'season' => 0, 'episode' => 0, 'airdate' => '2024-01-15'],
            ],
            'comma in the title' => [
                'Be.Cool,.Scooby-Doo!.S01E01.1080p.WEB-DL.x264-GRP',
                ['cleanname' => 'Be Cool Scooby-Doo', 'season' => 1, 'episode' => 1, 'airdate' => ''],
            ],
            'several commas in the title' => [
                'Rock,.Paper,.Scissors.S01E01.1080p.WEB.h264-IVy',
                ['cleanname' => 'Rock Paper Scissors', 'season' => 1, 'episode' => 1, 'airdate' => ''],
            ],
            'comma before a long title' => [
                'Nadia,.le.secret.de.leau.bleue.S01E29.FRENCH.1080p.WEB-DL-GRP',
                ['cleanname' => 'Nadia le secret de leau bleue', 'season' => 1, 'episode' => 29, 'airdate' => ''],
            ],
            'comma and apostrophe in the title' => [
                "Anyway,.I'm.Falling.In.Love.With.You.S02E01.1080p.WEB-DL-GRP",
                ['cleanname' => 'Anyway Im Falling In Love With You', 'season' => 2, 'episode' => 1, 'airdate' => ''],
            ],
            'host name prefix with a pipe' => [
                'PSArips.com | The.Penguin.S01E08.1080p.WEB.x265-PSA',
                ['cleanname' => 'The Penguin', 'season' => 1, 'episode' => 8, 'airdate' => ''],
            ],
            'another host name prefix with a pipe' => [
                'PSArips.com | Animal.Control.S05E07.1080p.WEB.x265-PSA',
                ['cleanname' => 'Animal Control', 'season' => 5, 'episode' => 7, 'airdate' => ''],
            ],
            'accented letter in the first word' => [
                'Caçadores.da.Verdade.S01E01.1080p.WEB-DL-GRP',
                ['cleanname' => 'Cacadores da Verdade', 'season' => 1, 'episode' => 1, 'airdate' => ''],
            ],
            'accented letter in the last word' => [
                'Voz.da.Consciência.S01E03.1080p.WEB-DL-GRP',
                ['cleanname' => 'Voz da Consciencia', 'season' => 1, 'episode' => 3, 'airdate' => ''],
            ],
            'accented letter in a short title' => [
                'La.Mesías.S01E01.1080p.WEB-DL-GRP',
                ['cleanname' => 'La Mesias', 'season' => 1, 'episode' => 1, 'airdate' => ''],
            ],
            'accented letter in a long title' => [
                'O.Mistério.da.Fazenda.Blind.Frog.S04E01.1080p.WEB-DL-GRP',
                ['cleanname' => 'O Misterio da Fazenda Blind Frog', 'season' => 4, 'episode' => 1, 'airdate' => ''],
            ],
        ];
    }
}
