<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\TvEpisodeCatalog;
use App\Services\Releases\TvReleaseMembership;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TvReleaseMembershipTest extends TestCase
{
    public function test_catalog_keeps_canonical_episode_identity_and_show_boundaries(): void
    {
        $catalog = new TvEpisodeCatalog(collect([
            (object) ['id' => 1, 'videos_id' => 10, 'series' => 2, 'episode' => 1],
            (object) ['id' => 2, 'videos_id' => 10, 'series' => 2, 'episode' => 1],
            (object) ['id' => 3, 'videos_id' => 20, 'series' => 2, 'episode' => 1],
            (object) ['id' => 4, 'videos_id' => 10, 'series' => 0, 'episode' => 1],
            (object) ['id' => 5, 'videos_id' => 10, 'series' => 2, 'episode' => 0],
        ]));
        $this->assertSame([1], $catalog->members(10, 2, [1, 1, 99]));
        $this->assertSame([3], $catalog->members(20, 2));
        $this->assertSame([4], $catalog->members(10, 0));
        $this->assertNull($catalog->linked(10, 3));
        $this->assertSame(['id' => 1, 'show' => 10, 'season' => 2], $catalog->linked(10, 2));
        $this->assertSame(2, $catalog->linked(10, 1)['season']);
    }

    /** @return iterable<string, array{string, list<int>, bool}> */
    public static function declarations(): iterable
    {
        yield 'range' => ['Show.S02E01-E03', [1, 2, 3], false];
        yield 'unicode range' => ['Show.S02E01–E03', [1, 2, 3], false];
        yield 'repeated season' => ['Show.S02E01-S02E03', [1, 2, 3], false];
        yield 'underscores' => ['Show_S02E01_EAC3', [1], false];
        yield 'discrete' => ['Show.S02E01E03', [1, 3], false];
        yield 'full season' => ['Show.S02.COMPLETE', [1, 2, 3], true];
        yield 'unverified' => ['Show.Season.Pack', [], false];
        yield 'other season' => ['Show.S03.COMPLETE', [], true];
        yield 'cross season range' => ['Show.S02E01-S03E03', [], false];
        yield 'reversed range' => ['Show.S02E03-E01', [], false];
        yield 'separated episode' => ['Show.S02.E03.Title.1080p', [3], false];
        yield 'spaced EP episode' => ['Show S02 EP02 Title', [2], false];
        yield 'separated range' => ['Show.S02.E01-S02.E03', [1, 2, 3], false];
        yield 'four digit season' => ['Show - S1940E09 - Title', [5], false];
        yield 'combined season' => ['Show.S02.COMBiNED.720p', [1, 2, 3], true];
        yield 'bare season' => ['Show (2010) S02 1080p BluRay 8bit', [1, 2, 3], true];
        yield 'fansub episode' => ['[Group] Show S2 - 03 [1080p]', [3], false];
        yield 'bare season naming a part' => ['Show.S02.Part.2.1080p', [], false];
        yield 'bare season naming an episode' => ['Show.S02.Title.Ep3.1080p', [], false];
        yield 'bare season naming 2x03' => ['Show.S02.2x03.1080p', [], false];
        yield 'season word alone' => ['Show.Season.2.1080p', [], false];
        yield 'episode in words' => ['Show S02 - Season 2 - Episode 3.01 Title 1080 x 1920', [3], false];
        yield 'episode range in words' => ['Show.S02.[Epi.01-03].1080p', [], false];
        yield 'bonus episode' => ['Show.S02-Bonus.Episode.1.1080p', [], false];
        yield 'single disc' => ['Show.S2_D2.1080p', [], false];
        yield 'multi-season set' => ['Show.S01-S02.1080p', [], false];
    }

    /** @return iterable<string, array{string, ?int, list<int>|null, bool}> */
    public static function shapes(): iterable
    {
        yield 'separated' => ['Supernatural.S01.E19.Provenance.1080p.WEB-DL.DDP5.1.H.264-GRP', 1, [19], false];
        yield 'four digit season' => ['Popeye the Sailor (1933) - S1940E09 - Popeye Presents Eugene the Jeep', 1940, [9], false];
        yield 'bare season pack' => ['Regular Show (2010) S01 1080p BluRay 8bit', 1, null, true];
        yield 'combined pack' => ['Radioactive.Emergency.S01.COMBiNED.720p.WEB-DL-GRP', 1, null, true];
        yield 'fansub episode' => ['[Erai-raws] Title S3 - 13 [1080p]', 3, [13], false];
        yield 'solid episode' => ['Show.S02E01E03', 2, [1, 3], false];
        yield 'complete pack' => ['Show.S02.COMPLETE', 2, null, true];
        yield 'episode in words' => ['Bigg Boss S13 - Season 13 - Episode 47 Title 1080 x 1920', 13, [47], false];
        yield 'single disc' => ['The.Show.S7_D2.1080p.DVDR-GRP', null, [], false];
        yield 'multi-season set' => ['Title.S01-S06.1080p.WEB-DL-GRP', null, [], false];
        yield 'bonus episode' => ['Show.S03-Bonus.Episode.1080p.WEB-DL-GRP', null, [], false];
        yield 'episode range in words' => ['Show.S03.[Epi.01-06].720p.BluRay-GRP', null, [], false];
    }

    #[DataProvider('shapes')]
    public function test_describe_reads_every_season_and_episode_shape(string $name, ?int $season, ?array $numbers, bool $fullSeason): void
    {
        $described = (new TvReleaseMembership)->describe((object) ['videos_id' => 10, 'tv_episodes_id' => 0, 'searchname' => $name]);

        $this->assertSame(['numbers' => $numbers, 'season' => $season, 'fullSeason' => $fullSeason, 'linked' => 0], $described);
    }

    #[DataProvider('declarations')]
    public function test_explicit_membership_stays_within_the_identified_show(string $name, array $expected, bool $fullSeason): void
    {
        $episodes = new Collection([
            (object) ['id' => 1, 'videos_id' => 10, 'series' => 2, 'episode' => 1],
            (object) ['id' => 2, 'videos_id' => 10, 'series' => 2, 'episode' => 2],
            (object) ['id' => 3, 'videos_id' => 10, 'series' => 2, 'episode' => 3],
            (object) ['id' => 4, 'videos_id' => 20, 'series' => 2, 'episode' => 2],
            (object) ['id' => 5, 'videos_id' => 10, 'series' => 1940, 'episode' => 9],
        ]);
        $release = (object) ['videos_id' => 10, 'tv_episodes_id' => 0, 'searchname' => $name];
        $catalog = new TvEpisodeCatalog($episodes);
        $membership = new TvReleaseMembership;
        $result = $membership->resolve($release, $catalog);
        $this->assertSame($expected, $result['episodes']);
        $this->assertSame($fullSeason, $result['fullSeason']);
        $release->videos_id = 0;
        $this->assertSame([], $membership->resolve($release, $catalog)['episodes']);
    }
}
