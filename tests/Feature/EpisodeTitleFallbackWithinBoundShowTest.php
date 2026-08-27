<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvReleaseContext;
use Database\Factories\VideoFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Distributor renumbering: the bound show's episode list holds the episode under a
 * different season/episode than the release claims, so only the episode title can
 * identify it.
 */
final class EpisodeTitleFallbackWithinBoundShowTest extends TestCase
{
    private int $videoId;

    /**
     * @var array<string, int>
     */
    private array $episodeIds = [];

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
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('series');
            $table->unsignedInteger('episode');
            $table->string('title')->default('');
            $table->string('firstaired')->default('');
        });

        $this->videoId = $this->createVideo('Batman The Animated Series', '1992-09-05');

        $officialLayout = [
            [1, 30, 'Tyger, Tyger'],
            [1, 34, 'I Am the Night'],
            [1, 35, "Almost Got 'Im"],
            [1, 39, 'Heart of Steel (1)'],
            [1, 40, 'Heart of Steel (2)'],
            [2, 1, 'Shadow of the Bat (1)'],
            [2, 2, 'Shadow of the Bat (2)'],
            [2, 3, 'Mudslide'],
            [2, 4, 'The Worry Men'],
            [2, 5, 'Paging the Crime Doctor'],
            [2, 6, 'House & Garden'],
            [2, 7, 'Sideshow'],
            [2, 8, 'A Bullet for Bullock'],
            [2, 9, 'Trial'],
            [2, 10, 'Avatar'],
        ];

        foreach ($officialLayout as [$series, $episode, $title]) {
            $this->episodeIds[sprintf('S%02dE%02d', $series, $episode)] = $this->createEpisode(
                $this->videoId,
                $series,
                $episode,
                $title,
            );
        }
    }

    #[Test]
    public function part_notation_variants_bind_the_renumbered_episode(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E11.Heart.of.Steel.Part.2.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
            expectedEpisodeId: $this->episodeIds['S01E40'],
        );

        $this->assertSame($this->videoId, $result->result->videoId);
        $this->assertSame($this->episodeIds['S01E40'], $result->result->episodeId);
    }

    #[Test]
    public function an_apostrophe_in_the_stored_title_still_binds_the_renumbered_episode(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E18.Almost.Got.Im.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
            expectedEpisodeId: $this->episodeIds['S01E35'],
        );

        $this->assertSame($this->episodeIds['S01E35'], $result->result->episodeId);
    }

    #[Test]
    public function a_comma_in_the_stored_title_still_binds_the_renumbered_episode(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E14.Tyger.Tyger.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
            expectedEpisodeId: $this->episodeIds['S01E30'],
        );

        $this->assertSame($this->episodeIds['S01E30'], $result->result->episodeId);
    }

    #[Test]
    public function an_ambiguous_title_segment_binds_nothing(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E11.Heart.of.Steel.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
        );

        $this->assertNull($result->result->episodeId);
    }

    #[Test]
    public function a_release_without_a_title_segment_keeps_current_behavior(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E11.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
        );

        $this->assertSame('', $result->parsedInfo['episode_title'] ?? null);
        $this->assertNull($result->result->episodeId);
    }

    #[Test]
    public function a_title_segment_below_the_threshold_binds_nothing(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E11.Some.Nonexistent.Title.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
        );

        $this->assertSame('Some Nonexistent Title', $result->parsedInfo['episode_title'] ?? null);
        $this->assertNull($result->result->episodeId);
    }

    #[Test]
    public function an_in_numbering_release_never_reaches_the_title_fallback(): void
    {
        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S01E34.I.am.the.Night.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
            expectedEpisodeId: $this->episodeIds['S01E34'],
            expectTitleFallback: false,
        );

        $this->assertSame($this->episodeIds['S01E34'], $result->result->episodeId);
    }

    #[Test]
    public function the_fallback_only_considers_episodes_of_the_bound_show(): void
    {
        $otherVideoId = $this->createVideo('Batman Beyond', '1999-01-10');
        $foreignEpisodeId = $this->createEpisode($otherVideoId, 3, 4, 'Kingdom Come');

        $result = $this->processThroughLocalDb(
            'Batman.The.Animated.Series.1992.S02E11.Kingdom.Come.1080p.AMZN.WEB-DL.DDP2.0.H.264-ALY',
        );

        $this->assertNotSame($foreignEpisodeId, $result->result->episodeId);
        $this->assertNull($result->result->episodeId);
    }

    private function processThroughLocalDb(
        string $searchName,
        int $expectedEpisodeId = 0,
        bool $expectTitleFallback = true,
    ): TvProcessingPassable {
        $provider = Mockery::mock(LocalDbProvider::class)->makePartial();
        $provider->shouldReceive('setVideoIdFound')
            ->once()
            ->with($this->videoId, 99, $expectedEpisodeId);

        if (! $expectTitleFallback) {
            $provider->shouldNotReceive('getByEpisodeTitle');
        }

        $showInfo = $provider->parseInfo($searchName);
        $this->assertIsArray($showInfo);

        $pipe = (new LocalDbPipe)->setEchoOutput(false);
        (new \ReflectionProperty(LocalDbPipe::class, 'localDb'))->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(
            new TvReleaseContext(99, $searchName, 1, 5000, videosId: $this->videoId),
        );
        $passable->setParsedInfo($showInfo);

        return $pipe->handle(
            $passable,
            static fn (TvProcessingPassable $handled): TvProcessingPassable => $handled,
        );
    }

    private function createVideo(string $title, string $started): int
    {
        $video = VideoFactory::new()->make([
            'title' => $title,
            'type' => 0,
            'started' => $started,
        ]);
        $video->saveQuietly();

        return (int) $video->getKey();
    }

    private function createEpisode(int $videoId, int $series, int $episode, string $title): int
    {
        return (int) DB::table('tv_episodes')->insertGetId([
            'videos_id' => $videoId,
            'series' => $series,
            'episode' => $episode,
            'title' => $title,
        ]);
    }
}
