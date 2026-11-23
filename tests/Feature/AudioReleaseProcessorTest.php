<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\MediaInfoProbe;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioTag;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\MediaTools;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\AudioProcessing\AudioDecodableLengthProbe;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioGenres;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\AudioProcessing\AudioRouting;
use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\AudioTagRenamer;
use App\Services\AudioProcessing\Contracts\AcousticFingerprintGenerator;
use App\Services\AudioProcessing\DTO\AcousticFingerprint;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\MediaInfo\Contracts\MediaInfoSnapshotWriter;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\MediaInfo\Enums\MediaInfoSourceKind;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mhor\MediaInfo\Attribute\Duration;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Attribute\Size;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\MediaInfo;
use Mhor\MediaInfo\Type\Audio;
use Mhor\MediaInfo\Type\General;
use Mhor\MediaInfo\Type\Video;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class AudioReleaseProcessorTest extends TestCase
{
    private string $tmpPath;

    private string $savePath;

    /** @var list<list<string>> */
    private array $downloads = [];

    /** @var array<string, array<string, mixed>> a download's result by its first message id; others get the default body */
    private array $downloadResults = [];

    /** @var list<int> */
    private array $timeoutCountsAtDownload = [];

    /** @var list<int> */
    private array $synchronized = [];

    /** @var list<list<string>> */
    private array $encoderCommands = [];

    /** @var list<string|false> the encoder's input file's bytes when each command ran */
    private array $encoderInputs = [];

    /** @var list<string> the name of each file MediaInfo was asked to read */
    private array $probedFiles = [];

    private bool $renamesFromTags = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid')->unique();
            $table->char('leftguid', 1)->default('a');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('searchname_normalized')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->string('fromname')->default('');
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->integer('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('proc_pp')->default(0);
            $table->boolean('iscategorized')->default(false);
            $table->boolean('isrenamed')->default(false);
            $table->boolean('is_trusted_name')->default(false);
            $table->string('name_source', 64)->nullable();
            $table->integer('haspreview')->default(-1);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('nzbstatus')->default(1);
            $table->unsignedBigInteger('size')->default(5 * 1024 * 1024);
            $table->double('completion')->default(100);
            $table->dateTime('postdate')->nullable();
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
            $table->primary(['releases_id', 'groups_id']);
        });

        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('videoformat')->nullable();
        });

        Schema::create('audio_data', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('audioid')->default(0);
            $table->string('audioformat')->nullable();
            $table->unique(['releases_id', 'audioid']);
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('root_categories_id')->nullable();
        });

        DB::table('root_categories')->insert([
            ['id' => Category::MUSIC_ROOT, 'generate_previews' => 1],
            ['id' => Category::PC_ROOT, 'generate_previews' => 1],
        ]);
        DB::table('categories')->insert([
            ['id' => Category::MUSIC_MP3, 'root_categories_id' => Category::MUSIC_ROOT],
            ['id' => Category::MUSIC_OTHER, 'root_categories_id' => Category::MUSIC_ROOT],
            ['id' => Category::PC_0DAY, 'root_categories_id' => Category::PC_ROOT],
        ]);

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('')->unique();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.sounds.lossless', 'forced_root_categories_id' => null],
        ]);

        $migration = require database_path('migrations/2026_08_21_090000_create_release_audio_tags_table.php');
        $migration->up();
        $evidenceMigrations = glob(database_path('migrations/*_create_release_audio_evidence_tables.php')) ?: [];
        $this->assertCount(1, $evidenceMigrations);
        (require $evidenceMigrations[0])->up();
        ProductionTables::fromAuthority()->create('audio_genres');
        ProductionTables::fromAuthority()->create('release_audio_genres');
        // The genre rule reads the release's music identity decisions and its album's genres.
        ProductionTables::fromAuthority()->create('release_music_identifications');
        ProductionTables::fromAuthority()->create('musicbrainz_release_group_genres');

        // The search driver is unreachable in tests and the refinement/rename
        // paths sync through it; swap it out rather than log a page of failures.
        Search::swap(Mockery::mock()->shouldIgnoreMissing());

        $this->tmpPath = $this->makeTempDirectory('nntmux-audio-processor').'/';
        $this->savePath = $this->makeTempDirectory('nntmux-audio-store').'/';
        $this->downloads = [];
        $this->timeoutCountsAtDownload = [];
        $this->synchronized = [];
        $this->encoderCommands = [];
        $this->encoderInputs = [];
        $this->probedFiles = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_tags_are_persisted_from_the_probe_article_before_the_rest_is_fetched(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [
                ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
                ['title' => '"02 - another track.mp3" yEnc', 'segments' => ['<second-1>', '<second-2>']],
                ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
                ['title' => '"Album.m3u" yEnc', 'segments' => ['<playlist>']],
                ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
            ],
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        // The probe article on its own, then the remaining head in one request; then the
        // one-article CUE sheet and log, read as evidence (the playlist is not read); then the
        // other track's first article alone.
        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>'], ['<cue>'], ['<log>'], ['<second-1>']], $this->downloads);

        $tags = ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail();
        $this->assertSame('Test Album', $tags->album);
        $this->assertSame('Test Artist', $tags->performer);
        $this->assertSame('MPEG Audio', $tags->audio_format);

        $evidence = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole();
        $this->assertCount(2, $evidence->nzb_manifest);
        $this->assertSame(['cue', 'playlist', 'eac_log'], array_column($evidence->sidecar_manifest, 'kind'));
        $this->assertTrue($evidence->source_file_complete);
        $this->assertTrue($evidence->source_starts_at_zero);
        $this->assertFalse($evidence->whole_duration_reliable);
        $this->assertFalse($evidence->only_one_track_probed);
        $this->assertNull($evidence->archive_manifest_complete);
        $this->assertSame('USRC17607839', $evidence->tracks()->where('source_ordinal', 1)->value('isrc'));
        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            $evidence->tracks()->where('source_ordinal', 1)->value('musicbrainz_recording_id'),
        );
    }

    public function test_the_first_article_of_every_other_posted_track_is_read_after_the_sidecars(): void
    {
        $release = $this->makeRelease();
        $nzb = [
            ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
            ['title' => '"x7Hq2.mp3" yEnc', 'segments' => ['<second-1>', '<second-2>']],
            ['title' => '"Pz91k.mp3" yEnc', 'segments' => ['<third-1>']],
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
            ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
        ];
        $sampled = $this->taggedContainer();
        $this->setTags($sampled, [
            'MusicBrainz Album Id' => '33333333-3333-4333-8333-333333333333',
            'MusicBrainz Release Group Id' => '44444444-4444-4444-8444-444444444444',
            'MusicBrainz Artist Id' => '55555555-5555-4555-8555-555555555555',
            'BARCODE' => '0123456789012', 'CATALOGNUMBER' => 'CAT-001', 'DISCID' => 'disc-one',
        ]);
        $probes = [];
        foreach ([2 => 'Second Song', 3 => 'Third Song'] as $ordinal => $title) {
            $probes['track-head-'.$ordinal.'.mp3'] = $this->headContainer('MPEG Audio', null, [
                'track_name' => $title, 'track_name_position' => (string) $ordinal, 'part_position' => '1',
                'performer' => 'Head Artist '.$ordinal, 'album' => 'Head Album', 'album_performer' => 'Head Album Artist',
                'recorded_date' => '2020-0'.$ordinal, 'ISRC' => 'USRC1760784'.$ordinal,
                'MusicBrainz Recording Id' => $ordinal.$ordinal.$ordinal.$ordinal.'0000-0000-4000-8000-000000000000',
                'MusicBrainz Album Id' => '99999999-9999-4999-8999-999999999999',
                'MusicBrainz Release Group Id' => '88888888-8888-4888-8888-888888888888',
                'MusicBrainz Artist Id' => '77777777-7777-4777-8777-777777777777',
                'BARCODE' => '9999999999999', 'CATALOGNUMBER' => 'HEAD-9', 'DISCID' => 'disc-head',
            ]);
        }

        // Today's capture of the same release: no head is readable.
        $this->downloadResults = ['<second-1>' => $this->failedDownload(), '<third-1>' => $this->failedDownload()];
        $this->makeProcessor($sampled, nzbContents: $nzb, probes: $probes)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->downloads = [];
        $this->downloadResults = [];

        $result = $this->makeProcessor($sampled, nzbContents: $nzb, probes: $probes)->process($release->refresh(), $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>'], ['<cue>'], ['<log>'], ['<second-1>'], ['<third-1>']], $this->downloads);
        [$today, $evidence] = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->orderBy('revision')->get()->all();
        $this->assertFalse($evidence->only_one_track_probed);
        $this->assertTrue($today->only_one_track_probed);
        $this->assertSame($this->evidenceRow($today, 1), $this->evidenceRow($evidence, 1), 'the sampled row holds what it holds today');
        $this->assertSame('33333333-3333-4333-8333-333333333333', $this->evidenceRow($evidence, 1)['musicbrainz_release_id']);

        foreach ([2 => 'Second Song', 3 => 'Third Song'] as $ordinal => $title) {
            $expected = [
                'title' => $title, 'performer' => 'Head Artist '.$ordinal, 'album' => 'Head Album', 'album_artist' => 'Head Album Artist',
                'recorded_date' => '2020-0'.$ordinal, 'track_number' => $ordinal, 'disc_number' => 1, 'isrc' => 'USRC1760784'.$ordinal,
                'musicbrainz_recording_id' => $ordinal.$ordinal.$ordinal.$ordinal.'0000-0000-4000-8000-000000000000',
                'musicbrainz_release_id' => null, 'musicbrainz_release_group_id' => null, 'musicbrainz_artist_id' => null,
                'barcode' => null, 'catalog_number' => null, 'disc_id_like' => null, 'raw_tags' => null,
            ];
            $actual = array_intersect_key($this->evidenceRow($evidence, $ordinal), $expected);
            ksort($expected);
            ksort($actual);
            $this->assertSame($expected, $actual, 'ordinal '.$ordinal);
        }
    }

    public function test_a_head_keeps_its_length_only_when_it_is_the_whole_files(): void
    {
        $release = $this->makeRelease();
        $files = [
            2 => ['flac', 2, 'FLAC', null],
            3 => ['mp3', 2, 'MPEG Audio', 5_000_000],
            4 => ['mp3', 2, 'MPEG Audio', 2048],
            5 => ['mp3', 2, 'MPEG Audio', null],
            6 => ['m4a', 2, 'MPEG-4', 5_000_000],
            7 => ['m4a', 1, 'MPEG-4', null],
            8 => ['mp3', 1, 'MPEG Audio', 2048],
        ];
        $nzb = [['title' => '"01 - track.flac" yEnc', 'segments' => ['<seg-1>']]];
        $probes = [];
        foreach ($files as $ordinal => [$extension, $articles, $format, $streamSize]) {
            $nzb[] = ['title' => '"file'.$ordinal.'.'.$extension.'" yEnc', 'segments' => array_map(static fn (int $article): string => '<f'.$ordinal.'-'.$article.'>', range(1, $articles)),
                ...($ordinal === 8 ? ['partstotal' => 17] : [])];
            $probes['track-head-'.$ordinal.'.'.$extension] = $this->headContainer($format, $streamSize, ['track_name' => 'Song '.$ordinal, 'duration' => new Duration(180_500)]);
        }

        $this->makeProcessor($this->taggedContainer(), nzbContents: $nzb, probes: $probes)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $evidence = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole();
        $rows = [];
        foreach (array_keys($files) as $ordinal) {
            $row = $this->evidenceRow($evidence, $ordinal);
            $rows[$ordinal] = [$row['whole_duration_seconds'], $row['whole_duration_reliable'], $row['source_file_complete']];
        }
        $this->assertSame([
            2 => [180.5, 1, 0],  // several articles, FLAC
            3 => [180.5, 1, 0],  // several articles, MP3 declaring more than the bytes read
            4 => [null, 0, 0],   // several articles, MP3 declaring no more than the bytes read
            5 => [null, 0, 0],   // several articles, MP3 declaring no size
            6 => [null, 0, 0],   // several articles, another format
            7 => [180.5, 1, 1],  // one article, the whole file
            8 => [null, 0, 0],   // one article listed of 17 declared parts
        ], $rows);
    }

    public function test_an_unreadable_head_leaves_its_row_as_it_is_today_and_never_changes_the_outcome(): void
    {
        $release = $this->makeRelease();
        $nzb = [['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>']]];
        foreach (range(2, 8) as $ordinal) {
            $nzb[] = ['title' => '"file'.$ordinal.'.mp3" yEnc', 'segments' => ['<f'.$ordinal.'>']];
        }
        $audioOnly = $this->headContainer('MPEG Audio', null, ['track_name' => 'Silent']);
        $noAudio = new MediaInfoContainer;
        $noAudio->setGeneral($audioOnly->getGeneral() ?? new General);
        $video = $this->headContainer('MPEG Audio', null, ['track_name' => 'Video']);
        $video->add(new Video);
        $probes = [
            'track-head-4.mp3' => new \RuntimeException('MediaInfo failed.'),
            'track-head-5.mp3' => $video,
            'track-head-6.mp3' => $noAudio,
            'track-head-8.mp3' => $this->headContainer('MPEG Audio', null, ['track_name' => 'Readable']),
        ];
        $unreadable = ['<f2>' => $this->failedDownload(), '<f3>' => $this->failedDownload(), '<f4>' => $this->failedDownload(),
            '<f5>' => $this->failedDownload(), '<f6>' => $this->failedDownload(), '<f7>' => $this->failedDownload(), '<f8>' => $this->failedDownload()];

        $this->downloadResults = $unreadable;
        $today = $this->makeProcessor($this->taggedContainer(), nzbContents: $nzb, probes: $probes)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->downloadResults = [
            '<f2>' => $this->failedDownload(),
            '<f3>' => ['success' => true, 'data' => str_repeat('x', 2048), 'groupUnavailable' => false, 'error' => null, 'crcFailures' => 1, 'crcFailed' => true],
        ];
        mkdir($this->tmpPath.'track-head-7.mp3');
        $result = $this->makeProcessor($this->taggedContainer(), nzbContents: $nzb, probes: $probes)->process($release->refresh(), $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame($today->crcFailures, $result->crcFailures);
        $this->assertSame(1, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
        [$before, $after] = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->orderBy('revision')->get()->all();
        foreach (range(2, 7) as $ordinal) {
            $this->assertSame($this->evidenceRow($before, $ordinal), $this->evidenceRow($after, $ordinal), 'ordinal '.$ordinal);
        }
        $this->assertSame('Readable', $this->evidenceRow($after, 8)['title']);
        $this->assertFalse($after->only_one_track_probed);
    }

    public function test_up_to_forty_other_tracks_are_read_and_a_release_with_more_is_left_as_it_is_today(): void
    {
        foreach ([40 => 40, 41 => 0] as $others => $expectedHeads) {
            DB::table('releases')->delete();
            $this->downloads = [];
            $release = $this->makeRelease();
            $nzb = [['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>']]];
            foreach (range(2, $others + 1) as $ordinal) {
                $nzb[] = ['title' => '"file'.$ordinal.'.mp3" yEnc', 'segments' => ['<f'.$ordinal.'>']];
            }
            // An audio entry with no message ids is never read and does not count.
            $nzb[] = ['title' => '"empty.mp3" yEnc', 'segments' => []];

            $this->makeProcessor($this->taggedContainer(), nzbContents: $nzb)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

            $this->assertCount(1 + $expectedHeads, $this->downloads, $others.' other tracks');
            $this->assertNotContains([''], $this->downloads);
            $evidence = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole();
            $this->assertSame($expectedHeads === 0, $evidence->only_one_track_probed);
            $this->assertSame($expectedHeads, $evidence->tracks()->where('source_ordinal', '>', 1)->whereNotNull('album')->count());
            if ($expectedHeads === 0) {
                // Today's evidence: every other row holds its posted name's hints alone.
                $this->assertSame(0, $evidence->tracks()->where('source_ordinal', '>', 1)->where(static function ($rows): void {
                    foreach (['title', 'performer', 'album', 'isrc', 'container', 'whole_duration_seconds', 'source_file_complete', 'source_starts_at_zero'] as $column) {
                        $rows->orWhereNotNull($column);
                    }
                })->count());
            }
        }
    }

    public function test_no_head_is_read_for_an_archive_a_video_probe_or_an_incomplete_release(): void
    {
        $loose = ['title' => '"02 - loose.flac" yEnc', 'segments' => []];
        $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [['title' => '"Invented.Album.rar" yEnc', 'segments' => ['<rar-1>']], $loose],
            archiveService: $this->archiveWithTrack('01 - Invented Song.flac', declaredSize: 2048, extractedBytes: 2048),
        )->process($this->makeRelease(), $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->assertSame([['<rar-1>']], $this->downloads, 'an archive source');

        $twoTracks = [
            ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>']],
            ['title' => '"02 - track.mp3" yEnc', 'segments' => ['<second-1>']],
        ];
        DB::table('releases')->delete();
        $this->downloads = [];
        $this->makeProcessor($this->videoContainer(), expectsPreview: false, expectsExtraXml: false, nzbContents: $twoTracks)
            ->process($this->makeRelease(), $this->tmpPath, 'alt.binaries.multimedia');
        $this->assertSame([['<seg-1>']], $this->downloads, 'a video probe');

        DB::table('releases')->delete();
        $this->downloads = [];
        $this->makeProcessor($this->taggedContainer(), expectsPreview: false, expectsExtraXml: false, nzbContents: $twoTracks)
            ->process($this->makeRelease(['completion' => 50]), $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->assertSame([], $this->downloads, 'an incomplete release');
    }

    public function test_heads_are_read_beside_the_sampled_file_and_leave_nothing_behind(): void
    {
        $release = $this->makeRelease();
        $this->downloadResults = [
            '<second-1>' => ['success' => true, 'data' => str_repeat('h', 1024), 'groupUnavailable' => false, 'error' => null, 'crcFailures' => 0, 'crcFailed' => false],
            '<third-1>' => ['success' => true, 'data' => str_repeat('h', 1024), 'groupUnavailable' => false, 'error' => null, 'crcFailures' => 0, 'crcFailed' => false],
        ];
        $this->makeProcessor($this->taggedContainer(), nzbContents: [
            ['title' => '"01 - track.flac" yEnc', 'segments' => ['<seg-1>', '<seg-2>']],
            ['title' => '"02 - track.flac" yEnc', 'segments' => ['<second-1>', '<second-2>']],
            ['title' => '"03 - track.flac" yEnc', 'segments' => ['<third-1>']],
        ], probes: [
            'track-head-2.flac' => $this->headContainer('FLAC', null, ['track_name' => 'Two']),
            'track-head-3.flac' => $this->headContainer('FLAC', null, ['track_name' => 'Three']),
        ])->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(['audio.flac', 'audio.flac', 'track-head-2.flac', 'track-head-3.flac'], $this->probedFiles);
        $inputs = array_filter($this->encoderInputs, static fn (string|false $input): bool => $input !== false);
        $this->assertNotSame([], $inputs);
        foreach ($inputs as $input) {
            $this->assertSame(str_repeat('x', 4096), $input, 'the preview is made from the sampled file\'s bytes');
        }
        $this->assertSame([], glob($this->tmpPath.'track-head-*') ?: []);
        $this->assertSame(['Two', 'Three'], ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()
            ->tracks()->where('source_ordinal', '>', 1)->orderBy('source_ordinal')->pluck('title')->all());
    }

    public function test_audio_processing_captures_the_sampled_file_as_a_complete_snapshot(): void
    {
        $release = $this->makeRelease();
        $headContainer = $this->taggedContainer();
        $completeContainer = $this->taggedContainer();
        $completeContainer->getGeneral()?->set('album', 'Complete album metadata');
        $writer = Mockery::mock(MediaInfoSnapshotWriter::class);
        $writer->shouldReceive('capture')
            ->once()
            ->withArgs(function (int $releaseId, MediaInfoContainer $captured, MediaInfoProbeContext $context) use ($release, $completeContainer): bool {
                return $releaseId === (int) $release->id
                    && $captured === $completeContainer
                    && $context->sourceKind === MediaInfoSourceKind::AudioProcessing
                    && $context->sourceFilename === '01 - track.mp3'
                    && $context->sourceCompleteness === MediaInfoSourceCompleteness::Complete;
            })
            ->andReturn(new MediaInfoProbe);
        $processor = $this->makeProcessor(
            $headContainer,
            mediaInfoSnapshots: $writer,
            completeContainer: $completeContainer,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');
    }

    public function test_failed_complete_file_reprobe_keeps_processing_with_partial_head_metadata(): void
    {
        $release = $this->makeRelease();
        $headContainer = $this->taggedContainer();
        $writer = Mockery::mock(MediaInfoSnapshotWriter::class);
        $writer->shouldReceive('capture')
            ->once()
            ->withArgs(fn (int $releaseId, MediaInfoContainer $captured, MediaInfoProbeContext $context): bool => $releaseId === (int) $release->id
                && $captured === $headContainer
                && $context->sourceCompleteness === MediaInfoSourceCompleteness::Partial)
            ->andReturn(new MediaInfoProbe);
        $processor = $this->makeProcessor(
            $headContainer,
            mediaInfoSnapshots: $writer,
            completeProbeThrows: true,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
    }

    public function test_audio_probe_that_is_handed_to_video_still_captures_unknown_completeness(): void
    {
        $release = $this->makeRelease();
        $container = $this->videoContainer();
        $writer = Mockery::mock(MediaInfoSnapshotWriter::class);
        $writer->shouldReceive('capture')
            ->once()
            ->withArgs(fn (int $releaseId, MediaInfoContainer $captured, MediaInfoProbeContext $context): bool => $releaseId === (int) $release->id
                && $captured === $container
                && $context->sourceCompleteness === MediaInfoSourceCompleteness::Unknown)
            ->andReturn(new MediaInfoProbe);
        $processor = $this->makeProcessor(
            $container,
            expectsPreview: false,
            expectsExtraXml: false,
            mediaInfoSnapshots: $writer,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.multimedia');
    }

    public function test_a_probe_that_finds_video_declines_without_fetching_anything_else(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->videoContainer(), expectsPreview: false, expectsExtraXml: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.multimedia');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame([['<seg-1>']], $this->downloads, 'Nothing past the probe article may be fetched.');

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $row->additional_pp_claim_token);
        $this->assertNull($row->additional_pp_claimed_at);
        // haspreview stays pending: the video path still owes this release a run.
        $this->assertSame(-1, (int) $row->haspreview);
        $this->assertDatabaseMissing('release_audio_tags', ['releases_id' => $release->id]);
    }

    public function test_pc_root_release_is_declined_before_nzb_or_nntp_work_and_the_additional_path_accepts_it(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update([
            'forced_root_categories_id' => Category::MUSIC_ROOT,
        ]);
        $release = $this->makeRelease([
            'categories_id' => Category::PC_0DAY,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'audio-worker',
        ]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            expectsNzbParse: false,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame([], $this->downloads, 'PC releases must be handed off before any NNTP download.');
        $this->assertSame('PC-root releases belong to the general post-processing path.', $result->reason);

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(AudioRouting::DECLINED_TOKEN, $row->additional_pp_claim_token);
        $this->assertNull($row->additional_pp_claimed_at);
        $this->assertSame(-1, (int) $row->haspreview);
        $this->assertSame([$release->id], $this->synchronized);
        $this->assertSame(
            [$release->id],
            AdditionalCandidateQuery::baseBuilder(minSizeBytes: 0, maxSizeBytes: 0)
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    public function test_a_probe_without_any_audio_stream_also_declines(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(new MediaInfoContainer, expectsPreview: false, expectsExtraXml: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame([['<seg-1>']], $this->downloads);
    }

    public function test_a_successful_run_records_the_preview_and_clears_the_pending_sentinels(): void
    {
        $release = $this->makeRelease(['pp_timeout_count' => 2]);
        $processor = $this->makeProcessor($this->taggedContainer());

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertTrue($result->previewCreated);

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(1, (int) $row->haspreview);
        $this->assertSame(0, (int) $row->passwordstatus);
        $this->assertSame(0, (int) $row->pp_timeout_count);
        $this->assertNull($row->additional_pp_claim_token);
        $this->assertSame([$release->id], $this->synchronized);

        $tags = ReleaseAudioTag::query()->where('releases_id', $release->id)->firstOrFail();
        $this->assertTrue($tags->has_preview);
        $this->assertSame('mp3', $tags->preview_extension);
        $this->assertSame('audio/mpeg', $tags->preview_mime);
        $this->assertSame(30, $tags->preview_seconds);
        $this->assertSame(4096, $tags->preview_bytes);
        $this->assertFileExists($this->savePath.'audio-guid.mp3');
        $this->assertFileExists($this->savePath.'audio-guid_spectrum.png');
        $this->assertTrue($tags->has_spectrogram);
    }

    public function test_a_short_source_records_the_full_emitted_clip_length(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->taggedContainer(), encoderSourceSeconds: 13.0);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame(
            13,
            ReleaseAudioTag::query()->where('releases_id', $release->id)->sole()->preview_seconds,
        );
        $command = $this->encoderCommands[0];
        $offsetIndex = array_search('-ss', $command, true);
        $lengthIndex = array_search('-t', $command, true);
        $this->assertIsInt($offsetIndex);
        $this->assertIsInt($lengthIndex);
        $this->assertSame('0', $command[$offsetIndex + 1]);
        $this->assertSame('13', $command[$lengthIndex + 1]);
    }

    public function test_a_root_with_preview_generation_disabled_keeps_the_tags_and_skips_the_encode(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->taggedContainer(), expectsPreview: false, previewsEnabled: false);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame(
            PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY,
            (int) DB::table('releases')->where('id', $release->id)->value('haspreview'),
        );
        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
        $this->assertDatabaseHas('release_audio_evidence', ['releases_id' => $release->id, 'revision' => 1]);
        $this->assertSame([], $this->encoderCommands, 'No ffmpeg work may be started for a disabled root.');
    }

    public function test_an_evidence_persistence_failure_does_not_fail_preview_generation(): void
    {
        Schema::dropIfExists('release_audio_evidence_tracks');
        Schema::dropIfExists('release_audio_evidence');
        $release = $this->makeRelease();

        $result = $this->makeProcessor($this->taggedContainer())
            ->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertTrue($result->previewCreated);
        $this->assertFileExists($this->savePath.'audio-guid.mp3');
    }

    public function test_an_unidentified_release_is_renamed_from_its_own_tags(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $this->renamesFromTags = true;
        $release = $this->makeRelease([
            'categories_id' => Category::MUSIC_OTHER,
            'videos_id' => 41,
            'tv_episodes_id' => 42,
            'movieinfo_id' => 47,
            'imdbid' => 'tt1234567',
            'musicinfo_id' => 43,
            'consoleinfo_id' => 44,
            'bookinfo_id' => 45,
            'anidbid' => 46,
            'gamesinfo_id' => 48,
        ]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $renamed = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame('Test Artist - Test Album (2019) MP3', $renamed->searchname);
        $this->assertSame(Category::MUSIC_MP3, (int) $renamed->categories_id);
        $this->assertSame(1, (int) $renamed->isrenamed);
        $this->assertSame(1, (int) $renamed->iscategorized);
        $this->assertSame(1, (int) $renamed->is_trusted_name);
        $this->assertSame(1, (int) $renamed->proc_pp);
        $this->assertSame(0, (int) $renamed->videos_id);
        $this->assertSame(0, (int) $renamed->tv_episodes_id);
        $this->assertNull($renamed->movieinfo_id);
        $this->assertNull($renamed->imdbid);
        $this->assertNull($renamed->musicinfo_id);
        $this->assertNull($renamed->consoleinfo_id);
        $this->assertNull($renamed->bookinfo_id);
        $this->assertNull($renamed->anidbid);
        $this->assertSame(0, (int) $renamed->gamesinfo_id);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === (int) $release->id
                && $event->newName === 'Test Artist - Test Album (2019) MP3'
                && $event->categoryOverride === Category::MUSIC_MP3,
        );
    }

    public function test_a_predb_matched_release_keeps_its_scene_name(): void
    {
        $this->renamesFromTags = true;
        $release = $this->makeRelease(['categories_id' => Category::MUSIC_OTHER, 'predb_id' => 7]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(
            'Some.Album-GROUP',
            DB::table('releases')->where('id', $release->id)->value('searchname'),
        );
        // The tags are still recorded; only the name is left alone.
        $this->assertDatabaseHas('release_audio_tags', ['releases_id' => $release->id, 'album' => 'Test Album']);
        $this->assertSame(
            'Some.Album-GROUP',
            ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()->release_snapshot['searchname'],
        );
    }

    public function test_renaming_is_off_by_default(): void
    {
        $release = $this->makeRelease(['categories_id' => Category::MUSIC_OTHER]);

        $this->makeProcessor($this->taggedContainer())->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(
            'Some.Album-GROUP',
            DB::table('releases')->where('id', $release->id)->value('searchname'),
        );
    }

    public function test_an_nzb_with_nothing_playable_is_settled_rather_than_left_pending(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => '"group.nfo" yEnc', 'segments' => ['<nfo>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame([], $this->downloads);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
    }

    public function test_an_archive_source_bumps_pp_timeout_count_before_fetching_and_resets_it_on_settlement(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            maxArchiveBytes: 1,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame([1], $this->timeoutCountsAtDownload);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('pp_timeout_count'));
    }

    #[DataProvider('archiveCrashGuardCounts')]
    public function test_an_archive_attempt_at_the_crash_guard_cap_settles_before_fetching(int $initialCount): void
    {
        $release = $this->makeRelease([
            'pp_timeout_count' => $initialCount,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'audio-worker',
        ]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('crash guard', $result->reason);
        $this->assertSame([], $this->downloads);

        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(0, (int) $row->haspreview);
        $this->assertSame(0, (int) $row->pp_timeout_count);
        $this->assertNull($row->additional_pp_claimed_at);
        $this->assertNull($row->additional_pp_claim_token);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function archiveCrashGuardCounts(): array
    {
        return [
            'attempt reaches cap' => [2],
            'legacy pending row is already at cap' => [3],
        ];
    }

    public function test_an_archive_fetch_that_hits_the_ceiling_settles_the_release(): void
    {
        $release = $this->makeRelease([
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'audio-worker',
        ]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            maxArchiveBytes: 1,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('fetch ceiling', $result->reason);
        $row = DB::table('releases')->where('id', $release->id)->first();
        $this->assertSame(0, (int) $row->haspreview);
        $this->assertNull($row->additional_pp_claimed_at);
        $this->assertNull($row->additional_pp_claim_token);
    }

    public function test_a_bare_wavpack_decode_failure_reports_the_missing_fallback_tool(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            nzbContents: [['title' => '01-track.wv', 'segments' => ['<wv-1>']]],
            wavPackFallbackAvailable: false,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('WavPack file requires wvunpack, which is not installed.', $result->reason);
        $this->assertSame(0, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
        $this->assertTrue(
            ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()->whole_duration_reliable,
        );
    }

    public function test_an_encrypted_archive_head_settles_passworded_without_an_extra_download(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [[
                'title' => 'Album.part01.rar',
                'segments' => ['<rar-1>', '<rar-2>', '<rar-3>'],
            ]],
            archivePassworded: true,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertStringContainsString('password protected', $result->reason);
        $this->assertSame(
            [['<rar-1>', '<rar-2>', '<rar-3>']],
            $this->downloads,
            'The encryption verdict must use the archive bytes already fetched.',
        );
        $this->assertSame(
            ReleaseBrowseService::PASSWD_RAR,
            (int) DB::table('releases')->where('id', $release->id)->value('passwordstatus'),
        );
    }

    public function test_split_seven_zip_audio_does_not_fetch_metadata_for_a_verdict(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.7z.001', 'segments' => ['<7z-head>']]],
            archivePassworded: false,
        );
        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->assertSame([['<7z-head>']], $this->downloads);
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE,
            (int) DB::table('releases')->where('id', $release->id)->value('passwordstatus'));
    }

    public function test_an_unencrypted_archive_without_audio_still_settles_not_passworded(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
            archivePassworded: false,
        );

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame([['<rar-1>']], $this->downloads);
        $this->assertSame(
            ReleaseBrowseService::PASSWD_NONE,
            (int) DB::table('releases')->where('id', $release->id)->value('passwordstatus'),
        );
    }

    public function test_an_incomplete_release_fails_before_downloading_any_audio_articles(): void
    {
        $release = $this->makeRelease(['completion' => 7]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('Source is only 7% complete.', $result->reason);
        $this->assertSame([], $this->downloads);
    }

    public function test_disabling_the_completion_threshold_preserves_audio_fetching(): void
    {
        $release = $this->makeRelease(['completion' => 7]);
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            minimumCompletionPercent: 0,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>']], $this->downloads);
    }

    public function test_an_archive_starting_on_a_later_volume_fails_after_one_download(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [
                ['title' => 'Album.part08.rar', 'segments' => ['<rar-8>']],
                ['title' => 'Album.part09.rar', 'segments' => ['<rar-9>']],
            ],
            archiveListings: [[
                'files' => [['name' => 'track.flac', 'size' => 100]],
                'hasPassword' => false,
                'isFirstVolume' => false,
            ]],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame(
            'Archive set starts mid-volume; the first volume is not in this release.',
            $result->reason,
        );
        $this->assertSame([['<rar-8>']], $this->downloads);
    }

    public function test_an_archive_with_a_crc_failed_article_reports_the_distinct_source_damage_reason(): void
    {
        $release = $this->makeRelease();
        $segments = array_map(static fn (int $segment): string => '<damaged-'.$segment.'>', range(1, 65));
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => $segments]],
            downloadResult: [
                'success' => false,
                'data' => null,
                'groupUnavailable' => false,
                'error' => 'Source articles failed CRC verification.',
                'crcFailures' => 1,
                'crcFailed' => true,
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::NoUsefulArtifacts, $result->outcome);
        $this->assertSame('Source articles failed CRC verification.', $result->reason);
        $this->assertSame(1, $result->crcFailures);
        $this->assertSame([array_slice($segments, 0, 64)], $this->downloads);
    }

    public function test_a_crc_failure_recovered_by_provider_fallback_does_not_label_clean_bytes_damaged(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [['title' => 'Album.part01.rar', 'segments' => ['<recovered>']]],
            archivePassworded: false,
            downloadResult: [
                'success' => true,
                'data' => str_repeat('x', 2048),
                'groupUnavailable' => false,
                'error' => null,
                'crcFailures' => 1,
                'crcFailed' => false,
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame('No usable audio file was found within 1 fetched archive volume(s).', $result->reason);
        $this->assertSame(1, $result->crcFailures);
    }

    public function test_a_video_only_archive_declines_to_the_general_path_after_two_listed_volumes(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            expectsPreview: false,
            expectsExtraXml: false,
            nzbContents: [
                ['title' => 'Album.part01.rar', 'segments' => ['<rar-1>']],
                ['title' => 'Album.part02.rar', 'segments' => ['<rar-2>']],
                ['title' => 'Album.part03.rar', 'segments' => ['<rar-3>']],
            ],
            archiveListings: [
                ['files' => [['name' => 'release.nfo', 'size' => 12]], 'hasPassword' => false],
                ['files' => [['name' => 'feature.mkv', 'size' => 500]], 'hasPassword' => false],
            ],
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::DeclinedToVideoPath, $result->outcome);
        $this->assertSame('The archive holds no audio files (found: mkv, nfo).', $result->reason);
        $this->assertSame([['<rar-1>'], ['<rar-2>']], $this->downloads);
        $this->assertSame(
            AudioRouting::DECLINED_TOKEN,
            DB::table('releases')->where('id', $release->id)->value('additional_pp_claim_token'),
        );
        $this->assertSame(
            [$release->id],
            AdditionalCandidateQuery::baseBuilder(minSizeBytes: 0, maxSizeBytes: 0)
                ->pluck('r.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    public function test_a_complete_archive_track_with_tags_and_an_isrc_is_fingerprinted_before_its_source_is_deleted(): void
    {
        $this->addFingerprintColumns();
        $release = $this->makeRelease();
        $fingerprints = $this->fingerprintSpy();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [['title' => '"Invented.Album.rar" yEnc', 'segments' => ['<rar-1>']]],
            archiveService: $this->archiveWithTrack('01 - Invented Song.flac', declaredSize: 2048, extractedBytes: 2048),
            fingerprints: $fingerprints,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        // The extracted source, while it still existed; cleanup removes it afterwards.
        $this->assertCount(1, $fingerprints->paths);
        $this->assertStringStartsWith($this->tmpPath, $fingerprints->paths[0]);
        $this->assertSame([true], $fingerprints->sourceExisted);
        $this->assertFileDoesNotExist($fingerprints->paths[0]);

        $track = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()
            ->tracks()->where('source_kind', 'archive')->sole();
        $this->assertSame('USRC17607839', $track->isrc);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $track->musicbrainz_recording_id);
        $this->assertTrue($track->source_file_complete);
        $this->assertTrue($track->source_starts_at_zero);
        $this->assertTrue($track->whole_duration_reliable);
        $this->assertSame(241.25, $track->whole_duration_seconds);
        $this->assertSame('AQADtEmUaEkSZSoAAAAA', $track->fingerprint);
        $this->assertSame(hash('sha256', 'AQADtEmUaEkSZSoAAAAA'), $track->fingerprint_hash);
        $this->assertSame(2, $track->fingerprint_algorithm);
        $this->assertSame('ffmpeg-chromaprint-120s-v1 ffmpeg/test', $track->fingerprint_generator_version);
    }

    public function test_a_truncated_archive_track_is_not_fingerprinted(): void
    {
        $this->addFingerprintColumns();
        $release = $this->makeRelease();
        $fingerprints = $this->fingerprintSpy();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [['title' => '"Invented.Album.rar" yEnc', 'segments' => ['<rar-1>']]],
            archiveService: $this->archiveWithTrack('01 - Invented Song.flac', declaredSize: 8192, extractedBytes: 2048),
            decodableSeconds: 300.0,
            fingerprints: $fingerprints,
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame([], $fingerprints->paths);
        $track = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()
            ->tracks()->where('source_kind', 'archive')->sole();
        $this->assertFalse($track->source_file_complete);
        $this->assertNull($track->fingerprint);
    }

    public function test_a_complete_bare_file_is_not_fingerprinted_yet(): void
    {
        $release = $this->makeRelease();
        $fingerprints = $this->fingerprintSpy();
        $processor = $this->makeProcessor($this->taggedContainer(), fingerprints: $fingerprints);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertTrue(ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()->source_file_complete);
        $this->assertSame([], $fingerprints->paths);
    }

    public function test_a_failed_fingerprint_still_records_evidence_and_the_preview(): void
    {
        $this->addFingerprintColumns();
        $release = $this->makeRelease();
        $processor = $this->makeProcessor(
            $this->taggedContainer(),
            nzbContents: [['title' => '"Invented.Album.rar" yEnc', 'segments' => ['<rar-1>']]],
            archiveService: $this->archiveWithTrack('01 - Invented Song.flac', declaredSize: 2048, extractedBytes: 2048),
            fingerprints: $this->fingerprintSpy(null),
        );

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $track = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole()
            ->tracks()->where('source_kind', 'archive')->sole();
        $this->assertSame('USRC17607839', $track->isrc);
        $this->assertNull($track->fingerprint);
        $this->assertNull($track->fingerprint_hash);
    }

    public function test_tag_genres_are_stored_one_row_per_genre_beside_the_raw_value(): void
    {
        ProductionTables::fromAuthority()->create('genres');
        DB::table('genres')->insert(['id' => 5, 'title' => 'Rock', 'type' => Category::MUSIC_ROOT, 'disabled' => 0]);
        $release = $this->makeRelease();
        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Rock; Pop; rock; Unknown');

        $this->makeProcessor($container)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame('Rock; Pop; rock; Unknown', ReleaseAudioTag::query()->where('releases_id', $release->id)->value('genre'));
        $this->assertSame([[0, 'Rock'], [1, 'Pop']], $this->storedGenres((int) $release->id));
        $this->assertSame([[5, 'Rock']], DB::table('genres')->get(['id', 'title'])
            ->map(static fn (object $row): array => [(int) $row->id, $row->title])->all());

        // The preview was written after the genres, and clearing it leaves them alone.
        $this->assertSame(1, (int) ReleaseAudioTag::query()->where('releases_id', $release->id)->value('has_preview'));
        ReleaseAudioTag::clearPreviews([(int) $release->id]);
        $this->assertSame([[0, 'Rock'], [1, 'Pop']], $this->storedGenres((int) $release->id));
    }

    public function test_only_one_article_cue_sheets_and_rip_logs_are_downloaded_after_the_audio(): void
    {
        $release = $this->makeRelease();
        $processor = $this->makeProcessor($this->taggedContainer(), nzbContents: [
            ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue-1>', '<cue-2>']],
            ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
            ['title' => '"Album.m3u" yEnc', 'segments' => ['<playlist>']],
        ]);

        $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame([['<seg-1>'], ['<seg-2>', '<seg-3>'], ['<log>']], $this->downloads, 'a multi-article CUE and a playlist are not downloaded');
    }

    public function test_a_failed_or_oversized_sidecar_changes_nothing_but_a_valid_cue_adds_its_rows(): void
    {
        $release = $this->makeRelease();
        $this->downloadResults = [
            '<cue>' => ['success' => true, 'data' => "FILE \"01 - track.wav\" WAVE\n TRACK 01 AUDIO\n  TITLE \"Cue Title\"\n  INDEX 01 00:00:00\n",
                'groupUnavailable' => false, 'error' => null, 'crcFailures' => 0, 'crcFailed' => false],
            '<log>' => ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => 'missing', 'crcFailures' => 1, 'crcFailed' => true],
            '<big>' => ['success' => true, 'data' => str_repeat('x', 65_536), 'groupUnavailable' => false, 'error' => null, 'crcFailures' => 0, 'crcFailed' => false],
        ];
        $processor = $this->makeProcessor($this->taggedContainer(), nzbContents: [
            ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
            ['title' => '"Album.cue" yEnc', 'segments' => ['<cue>']],
            ['title' => '"Album.log" yEnc', 'segments' => ['<log>']],
            ['title' => '"Big.log" yEnc', 'segments' => ['<big>']],
        ]);

        $result = $processor->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
        $this->assertSame(1, (int) DB::table('releases')->where('id', $release->id)->value('haspreview'));
        $evidence = ReleaseAudioEvidence::query()->where('releases_id', $release->id)->sole();
        $this->assertSame(['Cue Title'], $evidence->tracks()->where('source_kind', 'cue')->pluck('title')->all());
        $this->assertSame([[], [], []], array_map(static fn (array $entry): array => array_diff_key($entry['facts'] ?? [], ['cue_tracks' => true]), $evidence->sidecar_manifest));
    }

    public function test_reprocessing_keeps_the_accepted_albums_musicbrainz_genres(): void
    {
        $release = $this->makeRelease();
        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Rock');
        $this->makeProcessor($container)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');
        $evidence = DB::table('release_audio_evidence')->where('releases_id', $release->id)->orderByDesc('revision')->first(['id', 'evidence_hash']);
        $this->assertNotNull($evidence);
        $group = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        DB::table('release_music_identifications')->insert([
            'releases_id' => $release->id, 'release_audio_evidence_id' => $evidence->id, 'evidence_hash' => $evidence->evidence_hash,
            'state' => 'accepted_release_group', 'musicbrainz_release_group_id' => $group,
            'algorithm_version' => config('music-identity.algorithm_version'),
        ]);
        $indieRock = DB::table('audio_genres')->insertGetId(['name' => 'indie rock']);
        DB::table('musicbrainz_release_group_genres')->insert(['musicbrainz_release_group_id' => $group, 'audio_genres_id' => $indieRock, 'position' => 0]);

        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Jazz');
        $this->makeProcessor($container)->process($release->refresh(), $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertSame('Jazz', ReleaseAudioTag::query()->where('releases_id', $release->id)->value('genre'));
        $this->assertSame([[0, 'indie rock']], $this->storedGenres((int) $release->id));
    }

    public function test_reprocessing_a_release_replaces_its_genre_rows(): void
    {
        $release = $this->makeRelease();
        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Rock; Pop');
        $this->makeProcessor($container)->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Jazz');
        $this->makeProcessor($container)->process($release->refresh(), $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->assertSame([[0, 'Jazz']], $this->storedGenres((int) $release->id));

        $this->makeProcessor($this->taggedContainer())->process($release->refresh(), $this->tmpPath, 'alt.binaries.sounds.lossless');
        $this->assertSame([], $this->storedGenres((int) $release->id));
        $this->assertNull(ReleaseAudioTag::query()->where('releases_id', $release->id)->value('genre'));
    }

    public function test_a_failed_genre_write_leaves_no_tag_values_and_no_genre_rows(): void
    {
        $release = $this->makeRelease();
        $container = $this->taggedContainer();
        $container->getGeneral()?->set('genre', 'Rock');
        $repeating = new class extends AudioGenres
        {
            public function ids(array $names): array
            {
                return [1, 1];
            }
        };

        $this->makeProcessor($container, expectsExtraXml: false, audioGenres: $repeating)
            ->process($release, $this->tmpPath, 'alt.binaries.sounds.lossless');

        $this->assertDatabaseMissing('release_audio_tags', ['album' => 'Test Album']);
        $this->assertSame(0, DB::table('release_audio_genres')->where('releases_id', $release->id)->count());
    }

    /**
     * @return list<array{int, string}>
     */
    private function storedGenres(int $releaseId): array
    {
        return DB::table('release_audio_genres')
            ->join('audio_genres', 'audio_genres.id', '=', 'release_audio_genres.audio_genres_id')
            ->where('releases_id', $releaseId)->orderBy('position')->get(['position', 'name'])
            ->map(static fn (object $row): array => [(int) $row->position, (string) $row->name])->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRelease(array $attributes = []): Release
    {
        $id = DB::table('releases')->insertGetId(array_merge([
            'guid' => 'audio-guid',
            'searchname' => 'Some.Album-GROUP',
            'categories_id' => Category::MUSIC_MP3,
            'groups_id' => 1,
        ], $attributes));

        return Release::query()->findOrFail($id);
    }

    /**
     * @param  list<array<string, mixed>>|null  $nzbContents
     * @param  array<string, MediaInfoContainer|\Throwable>  $probes  a probe's container (or what it throws) by the
     *                                                                probed file's name; any other probe reads $container
     */
    private function makeProcessor(
        MediaInfoContainer $container,
        bool $expectsPreview = true,
        bool $expectsExtraXml = true,
        bool $previewsEnabled = true,
        ?array $nzbContents = null,
        ?int $maxArchiveBytes = null,
        ?bool $archivePassworded = null,
        bool $wavPackFallbackAvailable = true,
        float $minimumCompletionPercent = 95,
        ?array $archiveListings = null,
        ?array $downloadResult = null,
        float $encoderSourceSeconds = 300.0,
        bool $expectsNzbParse = true,
        ?MediaInfoSnapshotWriter $mediaInfoSnapshots = null,
        ?MediaInfoContainer $completeContainer = null,
        bool $completeProbeThrows = false,
        ?AudioGenres $audioGenres = null,
        ?ArchiveExtractionService $archiveService = null,
        float $decodableSeconds = 0.0,
        ?AcousticFingerprintGenerator $fingerprints = null,
        array $probes = [],
    ): AudioReleaseProcessor {
        $config = $this->config($maxArchiveBytes, $minimumCompletionPercent);

        $nzbParser = Mockery::mock(NzbContentParser::class);
        if ($expectsNzbParse) {
            $nzbParser->shouldReceive('parseNzb')->andReturn([
                'contents' => $nzbContents ?? [
                    ['title' => '"cover.jpg" yEnc', 'segments' => ['<jpg>']],
                    ['title' => '"01 - track.mp3" yEnc', 'segments' => ['<seg-1>', '<seg-2>', '<seg-3>']],
                ],
                'error' => null,
            ]);
        } else {
            $nzbParser->shouldNotReceive('parseNzb');
        }

        $downloadService = Mockery::mock(UsenetDownloadService::class);
        $downloadService->shouldReceive('download')->andReturnUsing(
            function (mixed $kind, array $messageIds) use ($downloadResult): array {
                $this->downloads[] = array_values($messageIds);
                $this->timeoutCountsAtDownload[] = (int) DB::table('releases')
                    ->where('id', 1)
                    ->value('pp_timeout_count');

                return $downloadResult ?? $this->downloadResults[$messageIds[0] ?? ''] ?? [
                    'success' => true,
                    'data' => str_repeat('x', 2048),
                    'groupUnavailable' => false,
                    'error' => null,
                    'crcFailures' => 0,
                    'crcFailed' => false,
                ];
            }
        );

        $mediaInfo = Mockery::mock(MediaInfo::class);
        $probeCount = 0;
        $mediaInfo->shouldReceive('getInfo')->andReturnUsing(
            function (string $path) use ($container, $completeContainer, $completeProbeThrows, $probes, &$probeCount): MediaInfoContainer {
                $this->probedFiles[] = basename($path);
                $probe = $probes[basename($path)] ?? null;
                if ($probe instanceof \Throwable) {
                    throw $probe;
                }
                if ($probe !== null) {
                    return $probe;
                }

                $probeCount++;
                if ($probeCount > 1 && $completeProbeThrows) {
                    throw new \RuntimeException('Complete-file probe failed.');
                }

                return $probeCount === 1 ? $container : ($completeContainer ?? $container);
            },
        );
        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'mediaInfo'))->setValue($tools, $mediaInfo);

        $encoder = new AudioPreviewEncoder(
            $config,
            $wavPackFallbackAvailable
                ? $this->encoderTools(sourceSeconds: $encoderSourceSeconds)
                : $this->encoderTools(
                    codec: 'wavpack',
                    commandFails: true,
                    wavPackFallbackAvailable: false,
                    sourceSeconds: $encoderSourceSeconds,
                ),
        );

        $archiveService ??= Mockery::mock(ArchiveExtractionService::class);
        if ($archiveListings !== null) {
            $archiveService->shouldReceive('listArchiveContentsAtPath')
                ->times(count($archiveListings))
                ->andReturn(...$archiveListings);
        } elseif ($archivePassworded !== null) {
            $archiveService->shouldReceive('listArchiveContentsAtPath')->once()->andReturn([
                'hasPassword' => $archivePassworded,
                'files' => [],
            ]);
        }

        $releaseExtra = Mockery::mock(ReleaseExtraService::class);
        $expectsExtraXml
            ? $releaseExtra->shouldReceive('addFromXml')->once()
            : $releaseExtra->shouldNotReceive('addFromXml');

        $previewPolicy = Mockery::mock(PreviewGenerationPolicy::class);
        $previewPolicy->shouldReceive('generationEnabledForCategory')->andReturn($previewsEnabled);

        return new AudioReleaseProcessor(
            $nzbParser,
            new AudioSourceSelector,
            new AudioFetcher(
                $config,
                $downloadService,
                $archiveService,
                $tools,
                Mockery::mock(AudioDecodableLengthProbe::class)->shouldIgnoreMissing($decodableSeconds),
            ),
            $encoder,
            new AudioTagExtractor,
            $this->renamer(),
            $releaseExtra,
            new MediaInfoRefinementService,
            new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                function (int $releaseId): void {
                    $this->synchronized[] = $releaseId;
                },
            ),
            $previewPolicy,
            new AudioEvidenceRecorder,
            $mediaInfoSnapshots,
            $audioGenres ?? new AudioGenres,
            $fingerprints,
        );
    }

    /**
     * ffmpeg is not installed on CI, so the encoder is driven by a recording
     * driver that writes a plausible artifact. What ffmpeg itself produces is
     * covered where the binary exists; what matters here is that the processor
     * records it.
     */
    private function encoderTools(
        string $codec = 'mp3',
        bool $commandFails = false,
        bool $wavPackFallbackAvailable = true,
        float $sourceSeconds = 300.0,
    ): MediaTools {
        $driver = Mockery::mock(FFMpegDriver::class);
        $driver->shouldReceive('command')->andReturnUsing(function (array $command) use ($commandFails): string {
            $this->encoderCommands[] = array_values(array_map('strval', $command));
            $input = array_search('-i', $command, true);
            $source = $input === false ? null : (string) $command[$input + 1];
            $this->encoderInputs[] = $source !== null && is_file($source) ? file_get_contents($source) : false;
            if ($commandFails) {
                throw new \RuntimeException('Forced ffmpeg WavPack decode failure.');
            }
            file_put_contents((string) end($command), str_repeat('p', 4096));

            return '';
        });

        $ffmpeg = Mockery::mock(FFMpeg::class);
        $ffmpeg->shouldReceive('getFFMpegDriver')->andReturn($driver);

        $ffprobe = Mockery::mock(FFProbe::class);
        $ffprobe->shouldReceive('streams')->andReturn(
            new StreamCollection([new Stream(['codec_type' => 'audio', 'codec_name' => $codec])])
        );
        $ffprobe->shouldReceive('format')->andReturn(new Format(['duration' => (string) $sourceSeconds]));

        $tools = new MediaTools;
        (new ReflectionProperty(MediaTools::class, 'ffmpeg'))->setValue($tools, $ffmpeg);
        (new ReflectionProperty(MediaTools::class, 'ffprobe'))->setValue($tools, $ffprobe);
        if (! $wavPackFallbackAvailable) {
            (new ReflectionProperty(MediaTools::class, 'wvunpackPath'))->setValue($tools, false);
        }

        return $tools;
    }

    private function renamer(): AudioTagRenamer
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'renameMusicMediaInfo'))
            ->setValue($config, $this->renamesFromTags);
        (new ReflectionProperty(AudioProcessingConfiguration::class, 'echoCLI'))->setValue($config, false);

        return new AudioTagRenamer(
            $config,
            new CategorizationService,
            new ReleaseUpdateService,
            new PreviewGenerationPolicy,
        );
    }

    private function addFingerprintColumns(): void
    {
        $migrations = glob(database_path('migrations/*_add_acoustic_fingerprints_to_release_audio_evidence_tracks.php')) ?: [];
        $this->assertCount(1, $migrations);
        (require $migrations[0])->up();
    }

    private function archiveWithTrack(string $name, int $declaredSize, int $extractedBytes): ArchiveExtractionService
    {
        $archive = Mockery::mock(ArchiveExtractionService::class);
        $archive->shouldReceive('listArchiveContentsAtPath')->andReturn([
            'files' => [['name' => $name, 'size' => $declaredSize]],
            'hasPassword' => false,
        ]);
        $archive->shouldReceive('extractSpecificFileToPath')->andReturnUsing(
            function () use ($name, $extractedBytes): string {
                $path = $this->tmpPath.$name;
                file_put_contents($path, str_repeat('f', $extractedBytes));

                return $path;
            },
        );

        return $archive;
    }

    /**
     * @return AcousticFingerprintGenerator&object{paths: list<string>, sourceExisted: list<bool>}
     */
    private function fingerprintSpy(?string $fingerprint = 'AQADtEmUaEkSZSoAAAAA'): AcousticFingerprintGenerator
    {
        return new class($fingerprint) implements AcousticFingerprintGenerator
        {
            /** @var list<string> */
            public array $paths = [];

            /** @var list<bool> */
            public array $sourceExisted = [];

            public function __construct(private readonly ?string $fingerprint) {}

            public function generate(string $sourcePath): ?AcousticFingerprint
            {
                $this->paths[] = $sourcePath;
                $this->sourceExisted[] = is_file($sourcePath);

                return $this->fingerprint === null
                    ? null
                    : new AcousticFingerprint($this->fingerprint, 2, 'ffmpeg-chromaprint-120s-v1 ffmpeg/test');
            }
        };
    }

    private function config(
        ?int $maxArchiveBytes = null,
        float $minimumCompletionPercent = 95,
    ): AudioProcessingConfiguration {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'segmentsToDownload' => 12,
            'maxRarParts' => 6,
            'maxArchiveBytes' => $maxArchiveBytes,
            'minimumCompletionPercent' => $minimumCompletionPercent,
            'previewSeconds' => 30,
            'previewStartSeconds' => 10,
            'spectrogram' => true,
            'savePath' => $this->savePath,
            'debugMode' => false,
            'echoCLI' => false,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }

    /**
     * One stored `nzb` row of a revision, without its own and its revision's ids.
     *
     * @return array<string, mixed>
     */
    private function evidenceRow(ReleaseAudioEvidence $evidence, int $ordinal): array
    {
        $row = (array) DB::table('release_audio_evidence_tracks')
            ->where('release_audio_evidence_id', $evidence->id)->where('source_kind', 'nzb')->where('source_ordinal', $ordinal)->sole();

        return array_diff_key($row, ['id' => true, 'release_audio_evidence_id' => true, 'created_at' => true, 'updated_at' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedDownload(): array
    {
        return ['success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => 'missing', 'crcFailures' => 0, 'crcFailed' => false];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function setTags(MediaInfoContainer $container, array $tags): void
    {
        foreach ($tags as $key => $value) {
            $container->getGeneral()?->set($key, $value);
        }
    }

    /**
     * Another posted track's first article as MediaInfo reads it.
     *
     * @param  int|null  $streamSize  the audio track's declared stream size in bytes; null leaves it out
     * @param  array<string, mixed>  $tags
     */
    private function headContainer(string $format, ?int $streamSize, array $tags = []): MediaInfoContainer
    {
        $general = new General;
        $general->set('format', new Mode($format, $format));
        $general->set('duration', new Duration(7_500));
        $container = new MediaInfoContainer;
        $container->setGeneral($general);
        $this->setTags($container, $tags);
        $audio = new Audio;
        if ($streamSize !== null) {
            $audio->set('stream_size', new Size($streamSize));
        }
        $container->add($audio);

        return $container;
    }

    private function taggedContainer(): MediaInfoContainer
    {
        $general = new General;
        foreach ([
            'format' => new Mode('MPEG Audio', 'MPEG Audio'),
            'album' => 'Test Album',
            'performer' => 'Test Artist',
            'recorded_date' => '2019-04-01',
            'duration' => new Duration(241250),
            'ISRC' => 'USRC17607839',
            'MusicBrainz Recording Id' => '11111111-1111-4111-8111-111111111111',
        ] as $key => $value) {
            $general->set($key, $value);
        }

        $container = new MediaInfoContainer;
        $container->setGeneral($general);
        $container->add(new Audio);

        return $container;
    }

    private function videoContainer(): MediaInfoContainer
    {
        $container = $this->taggedContainer();
        $container->add(new Video);

        return $container;
    }
}
