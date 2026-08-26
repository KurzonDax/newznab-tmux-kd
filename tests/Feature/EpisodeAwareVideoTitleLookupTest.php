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

final class EpisodeAwareVideoTitleLookupTest extends TestCase
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
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('videos_id');
            $table->unsignedInteger('series');
            $table->unsignedInteger('episode');
            $table->string('title')->default('');
            $table->string('firstaired')->default('');
        });
    }

    #[Test]
    public function an_episode_title_self_heals_a_same_titled_show_binding(): void
    {
        $ukVideoId = $this->createVideo('The Office', '2001-07-09');
        $usVideoId = $this->createVideo('The Office (US)', '2005-03-24');
        $this->createEpisode($ukVideoId, 1, 1, 'Downsize');
        $this->createEpisode($ukVideoId, 2, 1, 'Merger');
        $usEpisodeId = $this->createEpisode($usVideoId, 5, 12, 'Prince Family Paper');

        $result = $this->processThroughLocalDb(
            'The.Office.S05E12.Prince.Family.Paper.720p.HDTV.x264',
            currentVideoId: $ukVideoId,
            expectedVideoId: $usVideoId,
            expectedEpisodeId: $usEpisodeId,
        );

        $this->assertSame($usVideoId, $result->result->videoId);
        $this->assertSame($usEpisodeId, $result->result->episodeId);
        $this->assertSame('Prince Family Paper', $result->parsedInfo['episode_title'] ?? null);
    }

    #[Test]
    public function unique_episode_existence_selects_a_same_titled_show_without_an_episode_title(): void
    {
        $documentaryVideoId = $this->createVideo('Yellowstone', '2009-03-15');
        $dramaVideoId = $this->createVideo('Yellowstone (2018)', '2018-06-20');
        $this->createEpisode($documentaryVideoId, 1, 1, 'Winter');
        $dramaEpisodeId = $this->createEpisode($dramaVideoId, 2, 8, 'Behind Us Only Grey');

        $result = $this->processThroughLocalDb(
            'Yellowstone.S02E08.1080p.WEB-DL.DD5.1.H264',
            expectedVideoId: $dramaVideoId,
            expectedEpisodeId: $dramaEpisodeId,
        );

        $this->assertSame($dramaVideoId, $result->result->videoId);
        $this->assertSame($dramaEpisodeId, $result->result->episodeId);
        $this->assertSame('', $result->parsedInfo['episode_title'] ?? null);
    }

    #[Test]
    public function shared_episode_existence_without_a_title_keeps_exact_base_title_precedence(): void
    {
        $baseVideoId = $this->createVideo('Shared Show', '2001-01-01');
        $siblingVideoId = $this->createVideo('Shared Show (US)', '2020-01-01');
        $this->createEpisode($baseVideoId, 1, 1, 'Pilot');
        $this->createEpisode($siblingVideoId, 1, 1, 'Pilot');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Shared.Show.S01E01.1080p.WEB-DL');

        $this->assertIsArray($showInfo);
        $this->assertSame($baseVideoId, $provider->getByRelease($showInfo, 0));
    }

    #[Test]
    public function episode_title_similarity_below_the_threshold_keeps_current_precedence(): void
    {
        $baseVideoId = $this->createVideo('Castle', '2003-01-01');
        $modernVideoId = $this->createVideo('Castle (2009)', '2009-03-09');
        $this->createEpisode($baseVideoId, 8, 3, 'Completely Unrelated');
        $this->createEpisode($modernVideoId, 8, 3, 'Another Different Name');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Castle.S08E03.PhDead.720p.HDTV.x264');

        $this->assertIsArray($showInfo);
        $this->assertSame('PhDead', $showInfo['episode_title'] ?? null);
        $this->assertSame($baseVideoId, $provider->getByRelease($showInfo, 0));
    }

    #[Test]
    public function episode_evidence_compares_candidates_reached_through_aliases(): void
    {
        $ukVideoId = $this->createVideo('Office UK Official', '2001-07-09');
        $usVideoId = $this->createVideo('Office US Official', '2005-03-24');
        DB::table('videos_aliases')->insert([
            ['videos_id' => $ukVideoId, 'title' => 'The Office'],
            ['videos_id' => $usVideoId, 'title' => 'The Office (US)'],
        ]);
        $this->createEpisode($ukVideoId, 2, 1, 'Merger');
        $this->createEpisode($usVideoId, 5, 12, 'Prince Family Paper');

        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('The.Office.S05E12.Prince.Family.Paper.720p.HDTV.x264');

        $this->assertIsArray($showInfo);
        $this->assertSame($usVideoId, $provider->getByRelease($showInfo, 0));
    }

    private function processThroughLocalDb(
        string $searchName,
        int $currentVideoId = 0,
        int $expectedVideoId = 0,
        int $expectedEpisodeId = 0,
    ): TvProcessingPassable {
        $provider = Mockery::mock(LocalDbProvider::class)->makePartial();
        $provider->shouldReceive('setVideoIdFound')
            ->once()
            ->with($expectedVideoId, 99, $expectedEpisodeId);

        $showInfo = $provider->parseInfo($searchName);
        $this->assertIsArray($showInfo);

        $pipe = (new LocalDbPipe)->setEchoOutput(false);
        (new \ReflectionProperty(LocalDbPipe::class, 'localDb'))->setValue($pipe, $provider);

        $passable = new TvProcessingPassable(
            new TvReleaseContext(99, $searchName, 1, 5000, videosId: $currentVideoId),
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
