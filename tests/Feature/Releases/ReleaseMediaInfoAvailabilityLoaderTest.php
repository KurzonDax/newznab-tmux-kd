<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Facades\Search;
use App\Models\Release;
use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieBrowseService;
use App\Services\MusicService;
use App\Services\Releases\ReleaseMediaInfoAvailabilityLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseMediaInfoAvailabilityLoaderTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /** @return array<string, string> */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        foreach (['media_info_probes', 'media_infos', 'video_data', 'audio_data', 'release_subtitles', 'release_audio_tags'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->unsignedBigInteger('releases_id');
                foreach (match ($tableName) {
                    'media_info_probes' => ['embedded_title', 'source_filename', 'container_format', 'music_tags'],
                    'media_infos' => ['movie_name', 'file_name'],
                    'video_data' => ['containerformat', 'overallbitrate', 'videoduration', 'videoformat', 'videocodec', 'videoaspect', 'videolibrary'],
                    'audio_data' => ['audioformat', 'audiobitrate', 'audiochannels', 'audiosamplerate', 'audiolanguage', 'audiotitle'],
                    'release_subtitles' => ['subslanguage'],
                    'release_audio_tags' => ['album', 'performer', 'album_performer', 'genre', 'recorded_date', 'track_name', 'musicbrainz_album_id', 'musicbrainz_track_id', 'audio_format'],
                } as $column) {
                    $table->string($column)->nullable();
                }
                foreach (match ($tableName) {
                    'media_info_probes' => ['duration_ms', 'overall_bitrate_bps'],
                    'video_data' => ['videowidth', 'videoheight', 'videoframerate'],
                    'audio_data' => ['audioid'],
                    'release_subtitles' => ['subsid'],
                    'release_audio_tags' => ['track_position', 'track_position_total'],
                    default => [],
                } as $column) {
                    $table->unsignedBigInteger($column)->nullable();
                }
            });
        }
        Schema::create('media_info_tracks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_info_probe_id');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_marks_snapshot_audio_only_legacy_and_absent_rows_without_per_release_queries(): void
    {
        DB::table('media_info_probes')->insert(['releases_id' => 1, 'embedded_title' => 'Embedded title']);
        DB::table('audio_data')->insert(['releases_id' => 2, 'audioformat' => 'FLAC']);
        DB::table('release_subtitles')->insert(['releases_id' => 3, 'subslanguage' => 'English']);
        DB::table('release_audio_tags')->insert(['releases_id' => 4, 'album' => 'Tagged album']);
        DB::table('release_audio_tags')->insert(['releases_id' => 5, 'genre' => 'Jazz']);
        $trackOnlyProbeId = DB::table('media_info_probes')->insertGetId(['releases_id' => 7]);
        DB::table('media_info_tracks')->insert(['media_info_probe_id' => $trackOnlyProbeId]);
        DB::table('release_audio_tags')->insert(['releases_id' => 8]);
        $rows = array_map(static function (int $id): stdClass {
            $row = new stdClass;
            $row->id = $id;

            return $row;
        }, range(1, 8));
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        (new ReleaseMediaInfoAvailabilityLoader)->load($rows);

        self::assertSame([true, true, true, true, true, false, true, false], array_map(
            static fn (stdClass $row): bool => $row->has_media_info,
            $rows,
        ));
        self::assertLessThanOrEqual(9, $queries, 'Availability queries are bounded by source tables, not release count.');
    }

    #[DataProvider('coverListings')]
    public function test_cover_listings_load_current_media_info_and_render_the_action_on_cold_and_cached_pages(string $serviceClass, string $method, string $table, string $foreignKey): void
    {
        $this->createBrowseSchema($table);
        Cache::flush();
        Search::shouldReceive('isAvailable')->andReturn(false);
        config(['nntmux.echocli' => false]);
        foreach ([1, 2] as $id) {
            DB::table($table)->insert(['id' => $id, 'imdbid' => (string) $id, 'title' => 'Title '.$id, 'cover' => 1]);
            DB::table('releases')->insert(Release::factory()->make([
                'id' => $id, $foreignKey => $id, 'guid' => 'release-'.$id,
                'searchname' => 'Release '.$id, 'postdate' => '2026-09-13 12:00:00',
            ])->only(['id', $foreignKey, 'guid', 'searchname', 'postdate']));
        }
        DB::table('media_info_probes')->insert(['releases_id' => 1, 'embedded_title' => 'Embedded title']);
        DB::table('video_data')->insert(['releases_id' => 1, 'videoheight' => 1080, 'videocodec' => 'x264']);
        DB::table('audio_data')->insert(['releases_id' => 1, 'audioformat' => 'DTS-HD', 'audiochannels' => '5.1']);
        $service = app($serviceClass);

        $results = $service->{$method}(1, [], 0, 48, '');
        $rows = collect($results)->flatMap(static fn ($entity) => $entity->releases)->keyBy('id');
        self::assertSame('1080p · x264 · DTS-HD 5.1', $rows[1]->row_data?->media_info_summary);
        self::assertTrue($rows[1]->has_media_info ?? false);
        self::assertFalse($rows[2]->has_media_info ?? false);
        $this->blade('<x-cover-release-list :releases="$releases" />', ['releases' => $rows->values()])
            ->assertSee('data-release-id="1"', false)
            ->assertSee('data-release-display-name="Release 1"', false)
            ->assertDontSee('data-release-id="2"', false);

        if ($service instanceof MovieBrowseService) {
            $this->view('movies.partials.movie-card', ['result' => $results->first()])
                ->assertSee('data-release-id="1"', false)
                ->assertSee('data-release-display-name="Release 1"', false);
        }

        DB::table('media_info_probes')->delete();
        DB::table('video_data')->delete();
        DB::table('audio_data')->delete();
        DB::table('release_audio_tags')->insert(['releases_id' => 2, 'album' => 'Newly processed album']);
        $entityQueries = 0;
        DB::listen(static function ($query) use ($table, &$entityQueries): void {
            if (str_contains($query->sql, 'FROM '.$table.' ')) {
                $entityQueries++;
            }
        });
        $cached = $service->{$method}(1, [], 0, 48, '');
        $cachedRows = collect($cached)->flatMap(static fn ($entity) => $entity->releases)->keyBy('id');
        self::assertSame(0, $entityQueries, 'The second request should use the cached entity page.');
        self::assertFalse($cachedRows[1]->has_media_info);
        self::assertTrue($cachedRows[2]->has_media_info);
        $this->blade('<x-cover-release-list :releases="$releases" />', ['releases' => $cachedRows->values()])
            ->assertDontSee('data-release-id="1"', false)
            ->assertSee('data-release-id="2"', false);
    }

    /** @return array<string, array{class-string, string, string, string}> */
    public static function coverListings(): array
    {
        return [
            'Movies' => [MovieBrowseService::class, 'getMovieRange', 'movieinfo', 'imdbid'],
            'Audio' => [MusicService::class, 'getMusicRange', 'musicinfo', 'musicinfo_id'],
            'Games' => [GamesService::class, 'getGamesRange', 'gamesinfo', 'gamesinfo_id'],
            'Books' => [BookService::class, 'getBookRange', 'bookinfo', 'bookinfo_id'],
            'Console' => [ConsoleService::class, 'getConsoleRange', 'consoleinfo', 'consoleinfo_id'],
        ];
    }

    private function createBrowseSchema(string $entityTable): void
    {
        $this->registerSqliteFunction('YEAR', static fn (?string $date): ?string => $date === null ? null : substr($date, 0, 4));
        Schema::create($entityTable, function (Blueprint $table): void {
            $table->id();
            foreach (['imdbid', 'tmdbid', 'traktid', 'title', 'year', 'rating', 'plot', 'genre', 'director', 'actors', 'artist', 'publisher', 'releasedate', 'review', 'url', 'genres_id', 'author', 'publishdate', 'overview'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('cover')->default(1);
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            foreach (['imdbid', 'musicinfo_id', 'gamesinfo_id', 'bookinfo_id', 'consoleinfo_id', 'guid', 'searchname', 'display_name', 'repair_outcome', 'rescan_outcome', 'postdate', 'adddate'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['size', 'haspreview', 'videostatus', 'grabs', 'comments', 'totalpart', 'groups_id', 'categories_id', 'passwordstatus'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->integer('completion')->default(100);
        });
        foreach (['usenet_groups' => ['name'], 'release_nfos' => ['releases_id'], 'dnzb_failures' => ['release_id', 'failed'], 'genres' => ['title'], 'release_video_clips' => ['releases_id', 'extension', 'mime']] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns): void {
                $table->id();
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
        Schema::table('release_audio_tags', function (Blueprint $table): void {
            foreach (['preview_extension', 'preview_mime', 'preview_seconds'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('has_preview')->default(false);
            $table->boolean('has_spectrogram')->default(false);
        });
    }
}
