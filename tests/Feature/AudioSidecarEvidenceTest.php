<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Services\AudioProcessing\AudioEvidenceRecorder;
use App\Services\AudioProcessing\DTO\AcousticFingerprint;
use App\Services\AudioProcessing\DTO\AudioEvidenceFile;
use App\Services\AudioProcessing\DTO\AudioFetchResult;
use App\Services\AudioProcessing\DTO\AudioSource;
use App\Services\AudioProcessing\Enums\AudioSourceKind;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Evidence\AudioEvidenceSetFactory;
use App\Services\MusicIdentity\MusicCandidateGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;
use Tests\Unit\AudioProcessing\AudioSidecarReadingTest;

/**
 * Issue #313, section C: a CUE sheet's tracks become `cue` evidence rows that stand in for the
 * audio row they describe, and a rip log's TOC gives exact disc-ID lookups.
 */
final class AudioSidecarEvidenceTest extends TestCase
{
    private const string IMAGE = 'Example Artist - Example Album.flac';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        ProductionTables::fromAuthority()->create('releases', ['id', 'guid', 'name', 'searchname', 'categories_id', 'groups_id', 'size', 'postdate']);
        foreach (['*_create_release_audio_evidence_tables.php', '*_add_acoustic_fingerprints_to_release_audio_evidence_tracks.php', '*_create_release_music_renames_table.php'] as $pattern) {
            $this->migration($pattern)->up();
        }
        DB::table('releases')->insert(['id' => 1, 'guid' => 'sidecar-guid', 'name' => 'Example.Release', 'searchname' => 'Example Release',
            'categories_id' => 3040, 'groups_id' => 7, 'size' => 123_456_789, 'postdate' => '2026-10-08 12:00:00']);
    }

    #[Test]
    public function a_one_file_images_cue_posted_as_its_own_file_becomes_the_resolvers_track_list(): void
    {
        $evidence = $this->capture([2 => AudioSidecarReadingTest::CUE]);

        $cue = $evidence->tracks()->where('source_kind', 'cue')->orderBy('source_ordinal')->get();
        $this->assertSame([1, 2, 3], $cue->pluck('source_ordinal')->all());
        $this->assertSame([1, 2, 3], $cue->pluck('track_number')->all());
        $this->assertSame(['First Song', 'Second Song', 'Third Song'], $cue->pluck('title')->all());
        $this->assertSame(['Example Artist', 'Guest Artist', 'Example Artist'], $cue->pluck('performer')->all());
        $this->assertSame([240.0, 210.493, 149.507], $cue->pluck('whole_duration_seconds')->all());
        $this->assertSame([true, true, true], $cue->pluck('whole_duration_reliable')->all());
        $this->assertSame(['XXA111111111', 'XXA222222222', '000000000000'], $cue->pluck('isrc')->all());
        $this->assertSame(['0123456789012'], $cue->pluck('barcode')->unique()->values()->all());
        $this->assertSame('Example Album', $cue[0]->album);
        $this->assertSame('example artist', $cue[0]->normalized_album_artist);
        $this->assertSame('Example Artist - Example Album.wav', $cue[0]->raw_filename);
        $this->assertSame(3, $evidence->sidecar_manifest[0]['facts']['cue_tracks']);

        $set = (new AudioEvidenceSetFactory)->make($evidence->fresh());
        $this->assertSame(['cue', 'cue', 'cue'], array_column(array_map(static fn ($track): array => (array) $track, $set->trackEvidence), 'sourceKind'),
            'the image row the CUE describes leaves the list');
        $this->assertSame([240_000, 210_493, 149_507], array_map(static fn ($track): ?int => $track->durationMs, $set->trackEvidence));
        $this->assertTrue($set->trackEvidenceListComplete);
        $this->assertSame(1, $set->mediumCount);
        $this->assertSame('Example Album', $set->albumTitle, 'the sampled tag\'s album keeps precedence');
        $this->assertSame('33333333-3333-4333-8333-333333333333', $set->trackEvidence[0]->releaseId, 'a release id is carried onto the first track');
        $this->assertNull($set->trackEvidence[0]->recordingId, 'a per-track id is not carried onto one of several tracks');
    }

    #[Test]
    public function the_last_track_has_no_length_when_the_images_duration_is_not_reliable(): void
    {
        $evidence = $this->capture([2 => AudioSidecarReadingTest::CUE], reliable: false);

        $cue = $evidence->tracks()->where('source_kind', 'cue')->orderBy('source_ordinal')->get();
        $this->assertSame([240.0, 210.493, null], $cue->pluck('whole_duration_seconds')->all());
        $this->assertSame([true, true, null], $cue->pluck('whole_duration_reliable')->all());
    }

    #[Test]
    public function windows_1252_and_utf8_with_a_bom_read_the_same_title(): void
    {
        $utf8 = str_replace('"First Song"', '"Café Song"', AudioSidecarReadingTest::CUE);

        foreach (['windows-1252' => mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'), 'utf-8 bom' => "\xEF\xBB\xBF".$utf8] as $variant => $body) {
            $evidence = $this->capture([2 => $body]);

            $this->assertSame('Café Song', $evidence->tracks()->where('source_kind', 'cue')->orderBy('source_ordinal')->value('title'), $variant);
        }
    }

    #[Test]
    public function an_archive_cue_member_read_during_the_fetch_gives_the_same_rows(): void
    {
        $source = new AudioSource(AudioSourceKind::Archive, 'Album.part01.rar', '', [['<rar-1>']]);
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('sidecar-archive', '.flac'), 'flac', null,
            sampledFilename: 'Album/'.self::IMAGE,
            archiveMembers: [
                ['name' => 'Album/Example Artist - Example Album.cue', 'size' => strlen(AudioSidecarReadingTest::CUE), 'compressed' => 0],
                ['name' => 'Album/'.self::IMAGE, 'size' => 40_000_000, 'compressed' => 0],
            ],
            archiveManifestComplete: true, sourceFileComplete: true, sourceStartsAtZero: true, wholeDurationReliable: true, onlyOneTrackProbed: true,
        )->withSidecarBodies(['Album/Example Artist - Example Album.cue' => AudioSidecarReadingTest::CUE]);

        $evidence = (new AudioEvidenceRecorder)->record($this->release(), $source, $fetch, ['duration_seconds' => 600, 'album' => 'Example Album']);

        $this->assertSame([240.0, 210.493, 149.507], $evidence->tracks()->where('source_kind', 'cue')->orderBy('source_ordinal')->pluck('whole_duration_seconds')->all());
        $this->assertSame(['cue', 'cue', 'cue'], array_map(static fn ($track): string => $track->sourceKind, (new AudioEvidenceSetFactory)->make($evidence->fresh())->trackEvidence));
    }

    #[Test]
    public function a_cue_naming_one_file_per_track_carries_the_sampled_tracks_identifiers_and_fingerprint(): void
    {
        $cue = "PERFORMER \"Example Artist\"\nTITLE \"Example Album\"\n"
            ."FILE \"01 - First Song.flac\" WAVE\n  TRACK 01 AUDIO\n    TITLE \"First Song\"\n    INDEX 01 00:00:00\n"
            ."FILE \"02 - Second Song.flac\" WAVE\n  TRACK 02 AUDIO\n    TITLE \"Second Song\"\n    INDEX 01 00:00:00\n";
        $members = [
            ['name' => 'Album.cue', 'size' => strlen($cue), 'compressed' => 0],
            ['name' => '01 - First Song.flac', 'size' => 4000, 'compressed' => 0],
            ['name' => '02 - Second Song.flac', 'size' => 4000, 'compressed' => 0],
        ];
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('sidecar-tracks', '.flac'), 'flac', null,
            sampledFilename: '02 - Second Song.flac', archiveMembers: $members, archiveManifestComplete: true,
            sourceFileComplete: true, sourceStartsAtZero: true, wholeDurationReliable: true, onlyOneTrackProbed: true,
        )->withSidecarBodies(['Album.cue' => $cue]);

        $evidence = (new AudioEvidenceRecorder)->record(
            $this->release(),
            new AudioSource(AudioSourceKind::Archive, 'Album.part01.rar', '', [['<rar-1>']]),
            $fetch,
            ['duration_seconds' => 200.0, 'musicbrainz_recording_id' => '11111111-1111-4111-8111-111111111111', 'isrc' => 'USRC17607839'],
            fingerprint: new AcousticFingerprint('AQADtSyntheticSidecarFingerprint', 2, 'synthetic-generator-v1'),
        );
        $tracks = (new AudioEvidenceSetFactory)->make($evidence->fresh())->trackEvidence;

        $this->assertSame(['cue', 'cue'], array_map(static fn ($track): string => $track->sourceKind, $tracks), 'both files are described');
        $this->assertNull($tracks[0]->fingerprint);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $tracks[1]->recordingId);
        $this->assertSame('USRC17607839', $tracks[1]->isrc);
        $this->assertSame('AQADtSyntheticSidecarFingerprint', $tracks[1]->fingerprint);
        $this->assertSame(2, $tracks[1]->fingerprintAlgorithm);
        $this->assertSame(200_000, $tracks[1]->durationMs, 'its length from the file\'s reliable duration');
        $this->assertNull($tracks[0]->durationMs, 'the unsampled file has no reliable duration');
    }

    #[Test]
    public function a_malformed_cue_or_a_capture_without_one_adds_no_row_or_key(): void
    {
        $without = $this->capture([]);
        $malformed = $this->capture([2 => "FILE \"a.wav\" WAVE\n TRACK 01 AUDIO\n TRACK 02 AUDIO\n  INDEX 01 00:00:00\n"]);

        $this->assertSame($without->evidence_hash, $malformed->evidence_hash);
        $this->assertSame(0, $malformed->tracks()->where('source_kind', 'cue')->count());
        $this->assertSame([], $malformed->sidecar_manifest[0]['facts'] ?? []);
        $this->assertNotSame($without->evidence_hash, $this->capture([2 => AudioSidecarReadingTest::CUE])->evidence_hash, 'CUE rows make a new evidence revision');
    }

    #[Test]
    public function a_posted_rip_log_gives_its_disc_id_and_one_exact_disc_lookup(): void
    {
        $evidence = $this->capture([3 => AudioSidecarReadingTest::utf16Log(AudioSidecarReadingTest::eacLog()), 4 => "Lossless Audio Checker 2.0.6\nResult: Clean\n"]);

        $this->assertSame([AudioSidecarReadingTest::DISC_ID], $evidence->sidecar_manifest[1]['facts']['disc_ids']);
        $this->assertArrayNotHasKey('disc_ids', $evidence->sidecar_manifest[2]['facts'] ?? [], 'a checker report is not a rip log');
        $set = (new AudioEvidenceSetFactory)->make($evidence->fresh());
        $this->assertSame([['discId' => AudioSidecarReadingTest::DISC_ID, 'provenanceFamily' => 'evidence:'.$evidence->id.':sidecar:nzb:3']], $set->ripLogDiscIds);

        $gateway = new SidecarGatewayFake;
        $pool = (new MusicCandidateGenerator($gateway))->generate($set);

        $this->assertSame([AudioSidecarReadingTest::DISC_ID], array_values(array_filter(array_map(
            static fn (RecordingQuery $query): ?string => $query->normalized()['discId'],
            $gateway->recordingQueries,
        ))));
        $discSignals = [];
        foreach ($pool->candidates as $candidate) {
            foreach ($candidate->signals as $signal) {
                if ($signal->kind === CandidateSignalKind::DiscId) {
                    $discSignals[] = [$signal->value, $signal->exact, $signal->provenanceFamily];
                }
            }
        }
        $this->assertNotSame([], $discSignals, 'the matching candidates carry the disc signal');
        $this->assertSame([[AudioSidecarReadingTest::DISC_ID, true, 'evidence:'.$evidence->id.':sidecar:nzb:3']], array_values(array_unique($discSignals, SORT_REGULAR)));
    }

    #[Test]
    public function the_cue_catalog_and_valid_isrcs_reach_the_exact_queries_once_each(): void
    {
        $evidence = $this->capture([2 => AudioSidecarReadingTest::CUE]);
        $gateway = new SidecarGatewayFake;

        (new MusicCandidateGenerator($gateway))->generate((new AudioEvidenceSetFactory)->make($evidence->fresh()));

        $this->assertSame(['0123456789012'], array_values(array_filter(array_map(
            static fn (ReleaseQuery $query): ?string => $query->normalized()['barcode'],
            $gateway->releaseQueries,
        ))));
        $this->assertSame(['XXA111111111', 'XXA222222222'], array_values(array_filter(array_map(
            static fn (RecordingQuery $query): ?string => $query->normalized()['isrc'],
            $gateway->recordingQueries,
        ))));
    }

    /**
     * A one-file image posted bare beside its CUE (ordinal 2), a rip log (3) and a checker log (4),
     * with the posted bodies by ordinal.
     *
     * @param  array<int, string>  $bodies
     */
    private function capture(array $bodies, bool $reliable = true): ReleaseAudioEvidence
    {
        $source = new AudioSource(
            kind: AudioSourceKind::BareFile,
            title: self::IMAGE,
            extension: 'FLAC',
            parts: [['<image-1>']],
            nzbAudioFiles: [new AudioEvidenceFile(1, self::IMAGE, 40, 'audio')],
            sidecars: [
                new AudioEvidenceFile(2, 'Example Artist - Example Album.cue', 1, 'cue'),
                new AudioEvidenceFile(3, 'Example Artist - Example Album.log', 1, 'eac_log'),
                new AudioEvidenceFile(4, 'Lossless Audio Checker.log', 1, 'eac_log'),
            ],
        );
        $fetch = AudioFetchResult::fetched(
            $this->makeTempPath('sidecar-image', '.flac'), 'flac', null,
            sampledFilename: self::IMAGE, sourceFileComplete: true, sourceStartsAtZero: true, wholeDurationReliable: $reliable, onlyOneTrackProbed: true,
        );

        return (new AudioEvidenceRecorder)->record($this->release(), $source, $fetch, [
            'source_file' => self::IMAGE,
            'album' => 'Example Album',
            'album_performer' => 'Example Artist',
            'duration_seconds' => 600.0,
            'musicbrainz_album_id' => '33333333-3333-4333-8333-333333333333',
            'musicbrainz_recording_id' => '11111111-1111-4111-8111-111111111111',
        ], nzbSidecarBodies: $bodies);
    }

    private function release(): Release
    {
        return Release::query()->findOrFail(1);
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}

final class SidecarGatewayFake implements MusicBrainzGateway
{
    /** @var list<RecordingQuery> */
    public array $recordingQueries = [];

    /** @var list<ReleaseQuery> */
    public array $releaseQueries = [];

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        $this->recordingQueries[] = $query;
        if ($query->normalized()['discId'] === null) {
            return RecordingCandidates::empty();
        }

        return new RecordingCandidates([[
            'recordingId' => '66666666-6666-4666-8666-666666666666', 'title' => 'First Song', 'artistCredit' => 'Example Artist', 'lengthMs' => 240_000,
            'video' => false, 'isrcs' => [], 'releaseIds' => ['77777777-7777-4777-8777-777777777777'], 'releaseGroupIds' => ['88888888-8888-4888-8888-888888888888'],
            'providerScore' => 100, 'sources' => ['disc_id_lookup'],
        ]], 1);
    }

    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates
    {
        return ReleaseGroupCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        $this->releaseQueries[] = $query;

        return ReleaseCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        unset($identifiers);

        return CandidateMetadata::empty();
    }

    public function releaseGroup(string $releaseGroupId): ?array
    {
        unset($releaseGroupId);

        return null;
    }
}
