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
        $this->assertNull($catalog->linked(10, 2));
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
    }

    #[DataProvider('declarations')]
    public function test_explicit_membership_stays_within_the_identified_show(string $name, array $expected, bool $fullSeason): void
    {
        $episodes = new Collection([
            (object) ['id' => 1, 'videos_id' => 10, 'series' => 2, 'episode' => 1],
            (object) ['id' => 2, 'videos_id' => 10, 'series' => 2, 'episode' => 2],
            (object) ['id' => 3, 'videos_id' => 10, 'series' => 2, 'episode' => 3],
            (object) ['id' => 4, 'videos_id' => 20, 'series' => 2, 'episode' => 2],
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
