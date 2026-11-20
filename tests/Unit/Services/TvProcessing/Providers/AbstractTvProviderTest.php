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
            'Show.S01E02' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 1, 'episode' => 2, 'episode_title' => '', 'airdate' => ''],
            'Show.S01' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 1, 'episode' => 'all', 'episode_title' => '', 'airdate' => ''],
            'Show.2024.05.01' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 0, 'episode' => 0, 'episode_title' => '', 'airdate' => '2024-05-01'],
            'Show.S03E05.Episode.Title' => ['name' => 'Show', 'country' => '', 'cleanname' => 'Show', 'season' => 3, 'episode' => 5, 'episode_title' => '', 'airdate' => ''],
        ] as $name => $expected) {
            $this->assertEquals($expected, $provider->parseInfo($name), $name);
        }
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

    #[Test]
    public function a_pipe_after_the_episode_marker_keeps_todays_result(): void
    {
        $this->assertEquals(
            [
                'name' => 'Altered Carbon',
                'country' => 'CA',
                'cleanname' => 'Altered Carbon (2018)',
                'season' => 1,
                'episode' => 9,
                'episode_title' => '',
                'airdate' => '',
            ],
            (new LocalDbProvider)->parseInfo(
                'Altered Carbon S01E09 (2018) 1080p 10bit WEBRip | RZeroX ~ R3FL3X x265 DDP5.1 5.1 English ESub',
            ),
        );
    }

    #[Test]
    public function a_name_with_invalid_utf8_keeps_todays_result(): void
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
