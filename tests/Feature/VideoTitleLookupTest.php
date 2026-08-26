<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use Database\Factories\VideoFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class VideoTitleLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->tinyInteger('type')->default(0);
            $table->string('title');
            $table->dateTime('started');
            $table->tinyInteger('source')->default(0);
        });
        Schema::create('videos_aliases', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->string('title');
        });
    }

    #[Test]
    public function it_declines_an_implausibly_old_match_when_the_lookup_has_a_year(): void
    {
        $oldSeriesId = $this->createVideo('The Flash', '1990-09-20');

        $provider = new LocalDbProvider;

        $this->assertSame(0, $provider->getByTitle('The Flash (2014)', 0));
        $this->assertSame($oldSeriesId, $provider->getByTitle('The Flash', 0));
    }

    #[Test]
    public function it_uses_the_year_to_select_the_nearest_same_titled_series(): void
    {
        $ukSeriesId = $this->createVideo('House of Cards', '1990-11-18');
        $this->createVideo('Little House of Cards', '2013-02-01');
        $usSeriesId = $this->createVideo('House of Cards (US)', '2013-02-01');

        $provider = new LocalDbProvider;

        $this->assertSame($usSeriesId, $provider->getByTitle('House of Cards (2014)', 0));
        $this->assertSame($usSeriesId, $provider->getByTitle('House of Cards (2015)', 0));
        $this->assertSame($ukSeriesId, $provider->getByTitle('House of Cards (1990)', 0));
        $this->assertSame($ukSeriesId, $provider->getByTitle('House of Cards', 0));
        $this->assertSame($usSeriesId, $provider->getByTitle('House of Cards (US)', 0));
    }

    #[Test]
    public function it_prefers_a_year_suffixed_sibling_nearest_the_release_year(): void
    {
        $documentaryId = $this->createVideo('Yellowstone', '2009-03-15');
        $dramaId = $this->createVideo('Yellowstone (2018)', '2018-06-20');

        $provider = new LocalDbProvider;

        $this->assertSame($dramaId, $provider->getByTitle('Yellowstone (2018)', 0));
        $this->assertSame($dramaId, $provider->getByTitle('Yellowstone (2020)', 0));
        $this->assertSame($documentaryId, $provider->getByTitle('Yellowstone', 0));
    }

    #[Test]
    public function it_compares_candidates_reached_through_the_full_title_strategy_chain(): void
    {
        $this->createVideo('Batman Caped Crusader', '2020-09-05');
        $modernSeriesId = $this->createVideo('Batman: Caped Crusader (US)', '2024-08-01');

        $this->assertSame(
            $modernSeriesId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2025)', 0),
        );
    }

    #[Test]
    public function it_enforces_the_year_plausibility_window(): void
    {
        $pastBoundaryId = $this->createVideo('Past Boundary', '2019-01-01');
        $futureBoundaryId = $this->createVideo('Future Boundary', '2025-01-01');
        $this->createVideo('Late Future', '2025-02-01');
        $this->createVideo('Far Future', '2026-01-01');

        $provider = new LocalDbProvider;

        $this->assertSame($pastBoundaryId, $provider->getByTitle('Past Boundary (2024)', 0));
        $this->assertSame(0, $provider->getByTitle('Past Boundary (2025)', 0));
        $this->assertSame($futureBoundaryId, $provider->getByTitle('Future Boundary (2024)', 0));
        $this->assertSame(0, $provider->getByTitle('Late Future (2024)', 0));
        $this->assertSame(0, $provider->getByTitle('Far Future (2024)', 0));
    }

    #[Test]
    public function it_matches_a_year_suffixed_cleanname_to_a_colon_title(): void
    {
        $videoId = $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_preserves_matching_a_cleanname_without_a_year(): void
    {
        $videoId = $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader', 0),
        );
    }

    #[Test]
    public function it_preserves_year_stripped_exact_match_precedence(): void
    {
        $expectedVideoId = $this->createVideo('Batman Caped Crusader');
        $this->createVideo('Batman: Caped Crusader (2024)');

        $this->assertSame(
            $expectedVideoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_matches_a_year_suffixed_cleanname_to_an_apostrophe_title(): void
    {
        $videoId = $this->createVideo("Grey's Anatomy", '2005-03-27');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Greys Anatomy (2005)', 0),
        );
    }

    #[Test]
    public function it_loosely_matches_the_year_stripped_title_variant(): void
    {
        $videoId = $this->createVideo('Batman: The Caped Crusader');

        $this->assertSame(
            $videoId,
            (new LocalDbProvider)->getByTitle('Batman Caped Crusader (2024)', 0),
        );
    }

    #[Test]
    public function it_returns_zero_when_no_title_variant_matches(): void
    {
        $this->createVideo('Batman: Caped Crusader');

        $this->assertSame(
            0,
            (new LocalDbProvider)->getByTitle('Unrelated Show (2024)', 0),
        );
    }

    private function createVideo(string $title, string $started = '2024-01-01'): int
    {
        $video = VideoFactory::new()->make([
            'title' => $title,
            'type' => 0,
            'started' => $started,
        ]);
        $video->saveQuietly();

        return (int) $video->getKey();
    }
}
