<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\TvShowResolution;
use Database\Factories\VideoFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TvArtifactShowResolutionTest extends TestCase
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
            $table->string('imdb')->default('');
            $table->unsignedInteger('tmdb')->default(0);
            $table->unsignedInteger('trakt')->default(0);
            $table->unsignedInteger('tvdb')->default(0);
            $table->unsignedInteger('tvmaze')->default(0);
            $table->unsignedInteger('tvrage')->default(0);
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
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
        });
        Schema::create('media_infos', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
        });
        Schema::create('release_nfos', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->binary('nfo')->nullable();
        });
    }

    #[Test]
    public function an_inner_filename_disambiguates_a_bare_same_titled_release(): void
    {
        [$baseVideoId, $modernVideoId] = $this->createCastleCandidates();
        $episodeId = $this->createEpisode($modernVideoId, 'Flowers for Your Grave');
        DB::table('release_files')->insert([
            'releases_id' => 99,
            'name' => 'castle.2009.s01e01.720p.web.mkv',
        ]);

        $resolution = $this->resolveCastle();

        $this->assertNotSame($baseVideoId, $resolution->videoId);
        $this->assertSame($modernVideoId, $resolution->videoId);
        $this->assertFalse($resolution->isAmbiguous());
        $this->assertSame($episodeId, (new LocalDbProvider)->getBySeasonEp($resolution->videoId, 1, 1));
    }

    #[Test]
    public function a_tvdb_url_disambiguates_even_when_its_label_names_another_provider(): void
    {
        [, $modernVideoId] = $this->createCastleCandidates();

        foreach ([
            'http://thetvdb.com/?tab=series&id=83462&lid=7',
            'TV Rage............: http://thetvdb.com/?tab=series&id=83462&lid=7',
        ] as $nfo) {
            DB::table('release_nfos')->delete();
            $this->insertNfo($nfo);

            $this->assertSame($modernVideoId, $this->resolveCastle()->videoId);
        }
    }

    #[Test]
    public function a_mediainfo_movie_name_disambiguates_with_year_and_episode_title_evidence(): void
    {
        [, $modernVideoId] = $this->createCastleCandidates();
        DB::table('media_infos')->insert([
            'releases_id' => 99,
            'movie_name' => 'Castle (2009) - S01E01 - Flowers for Your Grave',
            'file_name' => 'media',
        ]);

        $this->assertSame($modernVideoId, $this->resolveCastle()->videoId);
    }

    #[Test]
    public function an_unresolved_nfo_id_is_non_evidence_and_defers(): void
    {
        $this->createCastleCandidates();
        $this->insertNfo('https://www.imdb.com/title/tt0055093/');

        $resolution = $this->resolveCastle();

        $this->assertSame(0, $resolution->videoId);
        $this->assertTrue($resolution->isAmbiguous());
    }

    #[Test]
    public function conflicting_nfo_ids_defer_instead_of_guessing(): void
    {
        $this->createCastleCandidates();
        $this->insertNfo(implode("\n", [
            'http://thetvdb.com/?tab=series&id=83462&lid=7',
            'http://www.tvmaze.com/shows/999/castle-classic',
        ]));

        $resolution = $this->resolveCastle();

        $this->assertSame(0, $resolution->videoId);
        $this->assertTrue($resolution->isAmbiguous());
    }

    #[Test]
    public function an_unrelated_artifact_title_is_ignored_and_defers(): void
    {
        $this->createCastleCandidates();
        $this->createVideo('Esprits rebelles', '1995-01-01');
        DB::table('media_infos')->insert([
            'releases_id' => 99,
            'movie_name' => 'Esprits rebelles S01E01 1080p',
            'file_name' => 'media',
        ]);

        $resolution = $this->resolveCastle();

        $this->assertSame(0, $resolution->videoId);
        $this->assertTrue($resolution->isAmbiguous());
    }

    #[Test]
    public function a_single_local_candidate_keeps_exact_title_behavior_without_artifacts(): void
    {
        $videoId = $this->createVideo('Castle', '2009-03-09');
        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Castle.S01E01.TR.EN');
        $this->assertIsArray($showInfo);

        $resolution = $provider->resolveByRelease($showInfo, 0, releaseId: 99);

        $this->assertSame($videoId, $resolution->videoId);
        $this->assertFalse($resolution->isAmbiguous());
    }

    #[Test]
    public function shared_episode_numbers_are_not_identity_evidence(): void
    {
        [$baseVideoId, $modernVideoId] = $this->createCastleCandidates();
        $this->createEpisode($baseVideoId, 'Pilot');
        $this->createEpisode($modernVideoId, 'Flowers for Your Grave');

        $resolution = $this->resolveCastle();

        $this->assertSame(0, $resolution->videoId);
        $this->assertTrue($resolution->isAmbiguous());
    }

    /** @return array{int, int} */
    private function createCastleCandidates(): array
    {
        return [
            $this->createVideo('Castle', '2003-09-13', tvdb: 78699, tvmaze: 999),
            $this->createVideo('Castle (2009)', '2009-03-09', tvdb: 83462, tvmaze: 210),
        ];
    }

    private function createVideo(
        string $title,
        string $started,
        int $tvdb = 0,
        int $tvmaze = 0,
    ): int {
        $video = VideoFactory::new()->make([
            'type' => 0,
            'title' => $title,
            'started' => $started,
            'source' => 0,
            'tvdb' => $tvdb,
            'tvmaze' => $tvmaze,
        ]);
        $video->saveQuietly();

        return (int) $video->getKey();
    }

    private function createEpisode(int $videoId, string $title): int
    {
        return (int) DB::table('tv_episodes')->insertGetId([
            'videos_id' => $videoId,
            'series' => 1,
            'episode' => 1,
            'title' => $title,
        ]);
    }

    private function insertNfo(string $text): void
    {
        DB::table('release_nfos')->insert([
            'releases_id' => 99,
            'nfo' => pack('V', strlen($text)).gzcompress($text),
        ]);
    }

    private function resolveCastle(): TvShowResolution
    {
        $provider = new LocalDbProvider;
        $showInfo = $provider->parseInfo('Castle.S01E01.TR.EN');
        $this->assertIsArray($showInfo);

        return $provider->resolveByRelease($showInfo, 0, releaseId: 99);
    }
}
