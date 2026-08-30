<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioEvidenceTrack;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\AudioProcessing\DTO\AudioEvidenceFile;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioEvidenceRecorderTest extends TestCase
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

        $this->evidenceMigration()->up();
    }

    #[Test]
    public function it_captures_a_bare_file_manifest_sidecars_tags_identifiers_and_complete_source_facts(): void
    {
        $release = $this->release();
        $source = new AudioSource(
            kind: AudioSourceKind::BareFile,
            title: '01 - First Song.flac',
            extension: 'FLAC',
            parts: [['<first-1>', '<first-2>']],
            nzbAudioFiles: [
                new AudioEvidenceFile(1, '01 - First Song.flac', 2, 'audio'),
                new AudioEvidenceFile(2, '02 - Second Song.flac', 3, 'audio'),
            ],
            sidecars: [
                new AudioEvidenceFile(3, 'Album.cue', 1, 'cue'),
                new AudioEvidenceFile(4, 'Album.m3u', 1, 'playlist'),
                new AudioEvidenceFile(5, 'Album.log', 1, 'eac_log'),
            ],
        );
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('audio-evidence-bare', '.flac'),
            'flac',
            null,
            sampledFilename: '01 - First Song.flac',
            sourceFileComplete: true,
            sourceStartsAtZero: true,
            wholeDurationReliable: true,
            onlyOneTrackProbed: true,
        );

        $evidence = (new AudioEvidenceRecorder)->record($release, $source, $fetch, [
            'source_file' => '01 - First Song.flac',
            'raw_tags' => ['album' => 'Example Album', 'ISRC' => 'USRC17607839'],
            'album' => 'Example Album',
            'album_performer' => 'Example Artist',
            'performer' => 'Example Artist',
            'track_name' => 'First Song',
            'recorded_date' => '2025-02-03',
            'container_format' => 'Free Lossless Audio Codec',
            'codec' => 'FLAC',
            'disc_position' => 1,
            'track_position' => 1,
            'duration_seconds' => 241.25,
            'isrc' => 'USRC17607839',
            'barcode' => '0123456789012',
            'catalog_number' => 'CAT-001',
            'disc_id' => 'disc-like-value',
            'musicbrainz_recording_id' => '11111111-1111-4111-8111-111111111111',
            'musicbrainz_track_id' => '22222222-2222-4222-8222-222222222222',
            'musicbrainz_album_id' => '33333333-3333-4333-8333-333333333333',
            'musicbrainz_release_group_id' => '44444444-4444-4444-8444-444444444444',
            'musicbrainz_artist_id' => '55555555-5555-4555-8555-555555555555',
        ]);

        $this->assertSame(1, $evidence->revision);
        $this->assertSame('captured', $evidence->provenance);
        $this->assertSame('Original.Release.Name', $evidence->release_snapshot['name']);
        $this->assertTrue($evidence->source_file_complete);
        $this->assertNull($evidence->archive_manifest_complete);
        $this->assertTrue($evidence->whole_duration_reliable);
        $this->assertTrue($evidence->only_one_track_probed);
        $this->assertCount(2, $evidence->nzb_manifest);
        $this->assertSame(3, $evidence->nzb_manifest[1]['segment_count']);
        $this->assertSame(['cue', 'playlist', 'eac_log'], array_column($evidence->sidecar_manifest, 'kind'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->evidence_hash);

        $tracks = $evidence->tracks()->orderBy('source_ordinal')->get();
        $this->assertCount(2, $tracks);
        $this->assertSame('nzb', $tracks[0]->source_kind);
        $this->assertSame(1, $tracks[0]->disc_number);
        $this->assertSame(1, $tracks[0]->track_number);
        $this->assertSame('first song', $tracks[0]->normalized_title_hint);
        $this->assertSame('USRC17607839', $tracks[0]->isrc);
        $this->assertSame('example album', $tracks[0]->normalized_album);
        $this->assertSame('example artist', $tracks[0]->normalized_album_artist);
        $this->assertSame('2025 02 03', $tracks[0]->normalized_date);
        $this->assertSame('Free Lossless Audio Codec', $tracks[0]->container);
        $this->assertSame('FLAC', $tracks[0]->codec);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $tracks[0]->musicbrainz_recording_id);
        $this->assertSame(['album' => 'Example Album', 'ISRC' => 'USRC17607839'], $tracks[0]->raw_tags);
        $this->assertSame(241.25, $tracks[0]->whole_duration_seconds);
        $this->assertTrue($tracks[0]->whole_duration_reliable);
        $this->assertTrue($tracks[0]->source_file_complete);
    }

    #[Test]
    public function it_captures_archive_members_with_honest_incomplete_flags_as_a_new_revision(): void
    {
        $release = $this->release();
        $recorder = new AudioEvidenceRecorder;
        $source = new AudioSource(
            kind: AudioSourceKind::Archive,
            title: 'Album.part01.rar',
            extension: '',
            parts: [['<rar-1>'], ['<rar-2>']],
            nzbAudioFiles: [],
            sidecars: [new AudioEvidenceFile(3, 'Album.cue', 1, 'cue')],
        );
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('audio-evidence-archive', '.flac'),
            'flac',
            null,
            sampledFilename: 'CD2/02 - Archive Song.flac',
            archiveMembers: [
                ['name' => 'CD1/02 - Archive Song.flac', 'size' => 4000, 'compressed' => 0],
                ['name' => 'CD2/02 - Archive Song.flac', 'size' => 4000, 'compressed' => 0],
                ['name' => 'cover.jpg', 'size' => 2000, 'compressed' => 1],
                ['name' => 'Album.cue', 'size' => 500, 'compressed' => 1],
            ],
            archiveManifestComplete: false,
            sourceFileComplete: false,
            sourceStartsAtZero: true,
            wholeDurationReliable: false,
            onlyOneTrackProbed: true,
            decodedDurationSeconds: 42.5,
        );

        $recorder->record($release, $source, $fetch, null);
        $evidence = $recorder->record($release, $source, $fetch, [
            'source_file' => '02 - Archive Song.flac',
            'raw_tags' => ['title' => 'Archive Song'],
            'track_name' => 'Archive Song',
        ]);

        $this->assertSame(2, $evidence->revision);
        $this->assertFalse($evidence->archive_manifest_complete);
        $this->assertFalse($evidence->source_file_complete);
        $this->assertFalse($evidence->whole_duration_reliable);
        $this->assertTrue($evidence->source_starts_at_zero);
        $this->assertSame('cover.jpg', $evidence->archive_manifest[2]['name']);
        $this->assertSame(['nzb', 'archive'], array_column($evidence->sidecar_manifest, 'source'));
        $this->assertSame(['cue', 'cue'], array_column($evidence->sidecar_manifest, 'kind'));
        $this->assertSame(2, ReleaseAudioEvidence::query()->where('releases_id', $release->id)->count());

        $tracks = $evidence->tracks()->orderBy('source_ordinal')->get();
        $this->assertCount(2, $tracks);
        $this->assertNull($tracks[0]->raw_tags);
        $track = $tracks[1];
        $this->assertSame('archive', $track->source_kind);
        $this->assertSame('CD2', $track->source_path);
        $this->assertSame(2, $track->disc_number);
        $this->assertSame(2, $track->track_number);
        $this->assertSame('archive song', $track->normalized_title_hint);
        $this->assertSame(['title' => 'Archive Song'], $track->raw_tags);
        $this->assertSame(42.5, $track->decoded_duration_seconds);
        $this->assertFalse($track->source_file_complete);
    }

    #[Test]
    public function evidence_factories_create_valid_header_and_track_rows(): void
    {
        $release = $this->release();
        $evidence = ReleaseAudioEvidence::factory()->create(['releases_id' => $release->id]);
        $track = ReleaseAudioEvidenceTrack::factory()->create([
            'release_audio_evidence_id' => $evidence->id,
        ]);

        $this->assertSame($release->id, $evidence->releases_id);
        $this->assertSame($evidence->id, $track->release_audio_evidence_id);
        $this->assertSame($track->id, $evidence->tracks()->sole()->id);
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

    private function evidenceMigration(): Migration
    {
        $paths = glob(database_path('migrations/*_create_release_audio_evidence_tables.php')) ?: [];
        $this->assertCount(1, $paths, 'The audio evidence migration is missing.');

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
