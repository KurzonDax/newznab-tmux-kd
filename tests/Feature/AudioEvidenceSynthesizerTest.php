<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbParseFailure;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\AudioProcessing\AudioEvidenceSynthesizer;
use App\Services\AudioProcessing\DTO\AudioEvidenceFile;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioEvidenceSynthesizerTest extends TestCase
{
    private string $nzbRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nzbRoot = $this->makeTempDirectory('synthesized-audio-evidence').DIRECTORY_SEPARATOR;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux_settings.path_to_nzbs' => $this->nzbRoot,
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->string('name');
            $table->string('searchname');
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id');
            $table->unsignedBigInteger('size');
            $table->dateTime('postdate')->nullable();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('passworded')->default(false);
            $table->string('crc32', 8)->default('');
            $table->timestamps();
        });

        $this->migration('*_create_release_audio_tags_table.php')->up();
        $this->migration('*_create_release_audio_evidence_tables.php')->up();
    }

    #[Test]
    public function it_lazily_synthesizes_immutable_evidence_from_the_stored_nzb_files_tags_and_release_snapshot(): void
    {
        $release = $this->release();
        $this->storeFixtureNzb($release);
        DB::table('release_files')->insert([
            [
                'releases_id' => $release->id,
                'name' => 'Recovered/03 - Stored Song.flac',
                'size' => 456789,
                'passworded' => false,
                'crc32' => 'A1B2C3D4',
            ],
            [
                'releases_id' => $release->id,
                'name' => 'Recovered/Album.log',
                'size' => 1234,
                'passworded' => false,
                'crc32' => '',
            ],
        ]);
        $rawTags = [
            'MUSICBRAINZ_RECORDINGID' => '11111111-1111-4111-8111-111111111111',
            'MUSICBRAINZ_RELEASETRACKID' => '22222222-2222-4222-8222-222222222222',
            'ISRC' => ['USRC17607839', 'USRC17607840'],
            'BARCODE' => '0123456789012',
            'CATALOGNUMBER' => 'CAT-001',
            'DISCID' => 'disc-like-value',
            'Duration' => '241.25 s',
        ];
        DB::table('release_audio_tags')->insert([
            'releases_id' => $release->id,
            'album' => 'Stored Album',
            'album_performer' => 'Stored Artist',
            'performer' => 'Track Artist',
            'track_name' => 'Stored Song',
            'track_position' => 3,
            'recorded_date' => '2024-05-06',
            'musicbrainz_album_id' => '33333333-3333-4333-8333-333333333333',
            'musicbrainz_artist_id' => '55555555-5555-4555-8555-555555555555',
            'musicbrainz_track_id' => '11111111-1111-4111-8111-111111111111',
            'musicbrainz_release_group_id' => '44444444-4444-4444-8444-444444444444',
            'source_file' => 'Recovered/03 - Stored Song.flac',
            'audio_format' => 'FLAC',
            'raw_tags' => json_encode($rawTags, JSON_THROW_ON_ERROR),
            'has_preview' => false,
            'has_spectrogram' => false,
        ]);

        $evidence = app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);

        $this->assertSame(1, $evidence->revision);
        $this->assertSame('synthesized', $evidence->provenance);
        $this->assertSame('Original.Release.Name', $evidence->release_snapshot['name']);
        $this->assertSame(3040, $evidence->release_snapshot['categories_id']);
        $this->assertNull($evidence->archive_manifest_complete);
        $this->assertNull($evidence->source_file_complete);
        $this->assertNull($evidence->source_starts_at_zero);
        $this->assertNull($evidence->whole_duration_reliable);
        $this->assertTrue($evidence->only_one_track_probed);
        $this->assertSame(
            [
                'Album.cue',
                'Disc 1/01 - First Song.flac',
                'Disc 1/02 - Second Song.flac',
                'Disc 2/03 - Partial Song.flac',
                'Stored.Album.part01.rar',
            ],
            array_column($evidence->nzb_manifest, 'filename'),
        );
        $this->assertSame([1, 2, 1, 0, 1], array_column($evidence->nzb_manifest, 'segment_count'));
        $this->assertSame(['cue', 'audio', 'audio', 'audio', 'archive'], array_column($evidence->nzb_manifest, 'kind'));
        $this->assertSame(['Recovered/03 - Stored Song.flac', 'Recovered/Album.log'], array_column($evidence->archive_manifest, 'name'));
        $this->assertSame(['nzb', 'release_file'], array_column($evidence->sidecar_manifest, 'source'));
        $this->assertSame(['cue', 'eac_log'], array_column($evidence->sidecar_manifest, 'kind'));

        $tracks = $evidence->tracks()->orderBy('source_kind')->orderBy('source_ordinal')->get();
        $this->assertCount(4, $tracks);
        $sampledTrack = $tracks->firstWhere('source_kind', 'release_file');
        $this->assertNotNull($sampledTrack);
        $this->assertSame('Recovered', $sampledTrack->source_path);
        $this->assertSame('stored album', $sampledTrack->normalized_album);
        $this->assertSame('USRC17607839', $sampledTrack->isrc);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $sampledTrack->musicbrainz_recording_id);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $sampledTrack->musicbrainz_track_id);
        $this->assertSame('33333333-3333-4333-8333-333333333333', $sampledTrack->musicbrainz_release_id);
        $this->assertSame($rawTags, $sampledTrack->raw_tags);
        $this->assertNull($sampledTrack->whole_duration_seconds);
        $this->assertNull($sampledTrack->decoded_duration_seconds);
        $this->assertNull($sampledTrack->source_file_complete);
        $this->assertNull($sampledTrack->whole_duration_reliable);
    }

    #[Test]
    public function it_returns_the_existing_revision_without_reading_a_missing_nzb_or_appending_another_row(): void
    {
        $release = $this->release();
        $existing = ReleaseAudioEvidence::factory()->create([
            'releases_id' => $release->id,
            'revision' => 1,
            'provenance' => 'captured',
        ]);

        $result = app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, ReleaseAudioEvidence::query()->where('releases_id', $release->id)->count());
    }

    #[Test]
    public function it_retries_when_nzb_storage_is_unavailable_instead_of_freezing_an_empty_revision(): void
    {
        $release = $this->release();
        $this->mock(NzbContentParser::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseNzb')->once()->andReturn([
                'contents' => [],
                'error' => 'NZB storage is unavailable',
                'failure' => NzbParseFailure::StorageUnavailable,
            ]);
        });

        try {
            app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);
            $this->fail('Unavailable NZB storage must leave synthesis retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('NZB storage is unavailable', $exception->getMessage());
        }

        $this->assertSame(0, ReleaseAudioEvidence::query()->where('releases_id', $release->id)->count());
    }

    #[Test]
    public function a_broken_individual_nzb_still_synthesizes_partial_evidence_from_stored_rows(): void
    {
        $release = $this->release();
        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => '01 - Stored Song.flac',
            'size' => 456789,
            'passworded' => false,
            'crc32' => '',
        ]);
        $this->mock(NzbContentParser::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseNzb')->once()->andReturn([
                'contents' => [],
                'error' => 'NZB is broken',
                'failure' => NzbParseFailure::Broken,
            ]);
        });

        $evidence = app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);

        $this->assertSame('synthesized', $evidence->provenance);
        $this->assertSame([], $evidence->nzb_manifest);
        $this->assertNull($evidence->archive_manifest_complete);
        $this->assertSame(['01 - Stored Song.flac'], $evidence->tracks()->pluck('raw_filename')->all());
    }

    #[Test]
    public function a_preview_only_tag_row_does_not_fabricate_a_sampled_track(): void
    {
        $release = $this->release();
        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => '01 - Stored Song.flac',
            'size' => 456789,
            'passworded' => false,
            'crc32' => '',
        ]);
        DB::table('release_audio_tags')->insert([
            'releases_id' => $release->id,
            'has_preview' => true,
            'preview_extension' => 'flac',
            'has_spectrogram' => false,
        ]);

        $evidence = app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);

        $this->assertNull($evidence->only_one_track_probed);
        $this->assertSame(['release_file'], $evidence->tracks()->pluck('source_kind')->all());
        $this->assertSame(['01 - Stored Song.flac'], $evidence->tracks()->pluck('raw_filename')->all());
    }

    #[Test]
    public function a_later_real_capture_appends_a_revision_without_editing_the_synthesized_one(): void
    {
        $release = $this->release();
        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => '01 - Stored Song.flac',
            'size' => 456789,
            'passworded' => false,
            'crc32' => '',
        ]);
        $synthesized = app(AudioEvidenceSynthesizer::class)->synthesizeIfMissing($release);
        $source = new AudioSource(
            AudioSourceKind::BareFile,
            '01 - Captured Song.flac',
            'FLAC',
            [['<captured>']],
            [new AudioEvidenceFile(1, '01 - Captured Song.flac', 1, 'audio')],
        );
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('captured-audio', '.flac'),
            'flac',
            null,
            sampledFilename: '01 - Captured Song.flac',
            sourceFileComplete: true,
            sourceStartsAtZero: true,
            wholeDurationReliable: true,
            onlyOneTrackProbed: true,
        );

        $captured = app(AudioEvidenceRecorder::class)->record($release, $source, $fetch, null);

        $this->assertSame(1, $synthesized->revision);
        $this->assertSame('synthesized', $synthesized->fresh()->provenance);
        $this->assertSame(2, $captured->revision);
        $this->assertSame('captured', $captured->provenance);
    }

    private function release(): Release
    {
        DB::table('releases')->insert([
            'id' => 1,
            'guid' => 'audio-evidence-guid',
            'name' => 'Original.Release.Name',
            'searchname' => 'Readable Release Name',
            'categories_id' => 3040,
            'groups_id' => 7,
            'size' => 987654321,
            'postdate' => '2026-08-29 15:30:00',
        ]);

        return Release::query()->findOrFail(1);
    }

    private function storeFixtureNzb(Release $release): void
    {
        $nzb = app(NzbService::class);
        $path = $nzb->getNzbPath((string) $release->guid, 1, true);
        $contents = File::get(base_path('tests/Fixtures/music-evidence.nzb'));
        File::put($path, gzencode($contents));
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths, 'Expected exactly one migration matching '.$pattern);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
