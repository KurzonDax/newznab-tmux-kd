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
     * @param  array{cleanname: string, season: int, episode: int, airdate: string}  $expected
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
    public function it_accepts_numeric_values_in_check_match(): void
    {
        Cache::flush();

        $provider = $this->makeProvider();

        $this->assertSame(100.0, $provider->checkMatch('2024', 2024, 80));
        $this->assertSame(0.0, $provider->checkMatch('2024', 2023, 100));
    }

    #[Test]
    public function it_returns_zero_for_non_comparable_values(): void
    {
        Cache::flush();

        $provider = $this->makeProvider();

        $this->assertSame(0.0, $provider->checkMatch('Example Show', ['bad'], 75));
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
     * @return array<string, array{string, array{cleanname: string, season: int, episode: int, airdate: string}}>
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
            'episode word' => [
                'Chernobyl.Episode.S01E03.1080p.WEB-DL.x264',
                ['cleanname' => 'Chernobyl', 'season' => 1, 'episode' => 3, 'airdate' => ''],
            ],
            'airdate' => [
                'Example.Show.2024.01.15.720p.HDTV.x264',
                ['cleanname' => 'Example Show', 'season' => 0, 'episode' => 0, 'airdate' => '2024-01-15'],
            ],
        ];
    }
}
