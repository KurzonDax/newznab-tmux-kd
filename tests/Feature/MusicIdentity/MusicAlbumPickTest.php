<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Facades\Search;
use App\Models\DecisionModelDecision;
use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheck;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentifiers;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\RecordingCandidates;
use App\Services\MusicIdentity\DTO\RecordingQuery;
use App\Services\MusicIdentity\DTO\ReleaseCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupCandidates;
use App\Services\MusicIdentity\DTO\ReleaseGroupQuery;
use App\Services\MusicIdentity\DTO\ReleaseQuery;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\ResolveReleaseMusicIdentity;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Resolution step 9 (issue #1275): in Apply mode, a decision the matcher left unresolved or for
 * review is put to the decision model while the release is matched, a confident pick of one of
 * the matcher's own candidates is linked as an ordinary matcher decision, and the answer is
 * written to the decision log once the decision is stored.
 */
final class MusicAlbumPickTest extends TestCase
{
    private const string ENDPOINT = 'https://decision.example.test/v1/systemone';

    private const string GROUP_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string RELEASE_A = 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1';

    private const string GROUP_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const string RELEASE_B = 'b1b1b1b1-b1b1-4b1b-8b1b-b1b1b1b1b1b1';

    private const string RELEASE_C = 'c1c1c1c1-c1c1-4c1c-8c1c-c1c1c1c1c1c1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
            'music-identity.musicbrainz.endpoint_url' => 'https://musicbrainz.test/ws/2/',
            'music-identity.lease_seconds' => 300,
            'music-identity.cover_art.base_url' => 'https://caa.test',
            'music-identity.cover_art.min_interval_milliseconds' => 0,
            'nntmux_settings.covers_path' => $this->makeTempDirectory('music-album-pick-covers'),
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
            'decision-model.circuit_breaker_failures' => 100,
        ]);
        Search::spy(); // decision writes re-sync the release search document, which these tests do not build
        DB::purge();
        DB::reconnect();

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('releases', null);
        $tables->create('release_files', ['releases_id', 'name']);
        $tables->create('releases_groups', ['releases_id', 'groups_id']);
        $tables->create('usenet_groups', ['id', 'forced_root_categories_id']);
        $tables->create('decision_model_criteria', null);
        $tables->create('decision_model_decisions', null);
        foreach ([
            '*_create_release_audio_tags_table.php',
            '*_add_release_audio_genres.php',
            '*_create_release_audio_evidence_tables.php',
            '*_create_release_music_identification_tables.php',
            '*_create_release_music_synthesis_attempts_table.php',
            '*_create_music_cover_art_lookups_table.php',
            '*_add_accepted_music_text_to_release_music_identifications.php',
            '*_create_musicbrainz_release_group_genres_table.php',
            '*_create_musicbrainz_release_tracks_table.php',
            '*_create_musicbrainz_artist_tables.php',
            '*_create_release_music_renames_table.php',
        ] as $pattern) {
            $this->migration($pattern)->up();
        }
        DB::table('settings')->insert([
            ['name' => 'music_identity_enabled', 'value' => '1'],
            ['name' => MusicAlbumCheck::SETTING, 'value' => 'apply'],
        ]);
        DB::table('usenet_groups')->insert(['id' => 1, 'forced_root_categories_id' => null]);

        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
    }

    #[Test]
    public function an_unresolved_decision_takes_the_album_the_model_confidently_picks(): void
    {
        $this->answer('2', 0.91);
        $release = $this->release();
        $this->evidence($release);
        DB::table('release_files')->insert([['releases_id' => $release->id, 'name' => 'FLAC/02 - Last Light.flac'], ['releases_id' => $release->id, 'name' => 'FLAC/01 - First Light.flac']]);

        $identification = $this->resolve($release, new PickCandidates([self::albumA(), self::albumB()]));

        $this->assertNotNull($identification);
        $stored = $identification->fresh();
        $this->assertNotNull($stored);
        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $stored->state);
        $this->assertSame([self::GROUP_B, self::RELEASE_B], [$stored->musicbrainz_release_group_id, $stored->musicbrainz_release_id]);
        $this->assertSame(['Example Album (Deluxe)', 'Example Artist', '2021-05-05'], [$stored->accepted_title, $stored->accepted_artist_credit, $stored->original_release_date]);
        $this->assertSame(58, $stored->score, 'the score stays the scorer\'s');
        $this->assertSame(['decision_model_pick'], array_column($stored->reasons, 'code'));
        $this->assertStringContainsString('0.91', $stored->reasons[0]['description']);
        $this->assertNull($stored->next_attempt_at, 'an accepted album is not looked at again');
        $this->assertSame([self::GROUP_B, self::GROUP_A], $this->storedCandidateGroups($stored->id), 'the accepted candidate is stored first');
        $this->assertSame(
            [['First Light', 180_000], ['Nowhere', 210_000]],
            DB::table('musicbrainz_release_tracks')->where('musicbrainz_release_id', self::RELEASE_B)->orderBy('track_position')->get(['title', 'length_ms'])->map(static fn (object $row): array => [$row->title, (int) $row->length_ms])->all(),
        );
        $this->assertSame(['Electronic', 'Ambient'], $this->genresOf(self::GROUP_B));
        $this->assertDatabaseHas('release_music_renames', ['release_music_identification_id' => $stored->id, 'outcome' => 'declined', 'reason' => 'score_below_minimum']);

        $row = DecisionModelDecision::query()->with('criteria')->sole();
        $this->assertSame('music_album_check', $row->criteria?->use_case);
        $this->assertSame(['release_music_identification', $stored->id, 'apply', true, null], [$row->subject_type, $row->subject_id, $row->mode, $row->applied, $row->failure_reason]);
        $this->assertSame(['release_name' => 'Example Artist - Example Album FLAC', 'files' => ['01 - First Light.flac', '02 - Last Light.flac']], $row->state);
        $this->assertSame([
            'state' => 'unresolved',
            'band' => 'unresolved',
            'score' => 62,
            'accepted_release_group_id' => null,
            'candidate_count' => 2,
            'untitled_candidates' => 0,
            'options' => [
                '1' => ['rank' => 1, 'release_group_id' => self::GROUP_A, 'label' => 'Example Artist - Example Album (2020)'],
                '2' => ['rank' => 2, 'release_group_id' => self::GROUP_B, 'label' => 'Example Artist - Example Album (Deluxe) (2021)'],
            ],
            'matcher_renamed' => false,
            'releases_id' => $release->id,
        ], $row->pipeline_answer);
        $this->assertSame(['2', 2, self::GROUP_B, true], [
            $row->interpretation['choice'] ?? null, $row->interpretation['picked_rank'] ?? null,
            $row->interpretation['picked_release_group_id'] ?? null, $row->interpretation['would_link'] ?? null,
        ]);

        $this->assertSame(0, app(MusicAlbumCheck::class)->catchUp(), 'the shadow catch-up does not ask about the logged decision again');
        $this->assertSame(1, $this->questionsAsked());
    }

    #[Test]
    public function a_decision_for_review_takes_the_album_the_model_confidently_picks(): void
    {
        $this->answer('1', 0.85);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates([self::embeddedAlbumA(), self::albumB()]));

        $this->assertNotNull($identification);
        $this->assertSame([IdentificationStatus::AcceptedReleaseGroup, self::GROUP_A], [$identification->state, $identification->musicbrainz_release_group_id]);
        $this->assertSame('Example Album', $identification->fresh()?->accepted_title);
        $this->assertSame(['decision_model_pick'], array_column($identification->fresh()?->reasons ?? [], 'code'));
        $this->assertSame(['Electronic', 'Ambient'], $this->genresOf(self::GROUP_A));
        $row = DecisionModelDecision::query()->sole();
        $this->assertSame(['apply', true, 'needs_review'], [$row->mode, $row->applied, $row->pipeline_answer['state'] ?? null]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modesThatDoNotApply(): array
    {
        return ['shadow' => ['shadow'], 'off' => ['off']];
    }

    #[Test]
    #[DataProvider('modesThatDoNotApply')]
    public function in_shadow_or_off_mode_the_matcher_decision_stands_and_nothing_is_asked(string $mode): void
    {
        DB::table('settings')->where('name', MusicAlbumCheck::SETTING)->update(['value' => $mode]);
        $this->answer('2', 0.91);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates([self::albumA(), self::albumB()]));

        $this->assertSame(IdentificationStatus::Unresolved, $identification?->state);
        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    /**
     * @return array<string, array{list<CandidateHypothesis>, string, float, IdentificationStatus}>
     */
    public static function picksThatAreDeclined(): array
    {
        return [
            'none' => [[self::albumA(), self::albumB()], 'none', 0.95, IdentificationStatus::Unresolved],
            'below 0.80' => [[self::albumA(), self::albumB()], '2', 0.79, IdentificationStatus::Unresolved],
            'a candidate without a release group' => [[self::albumA(), PickCandidates::album(self::RELEASE_C, null, 'Loose Album', 'Example Artist', '2020-01-01')], '2', 0.95, IdentificationStatus::Unresolved],
            'a contradicted album' => [[self::albumA(), PickCandidates::album(self::RELEASE_C, self::GROUP_B, 'Other Album', 'Someone Else', '2020-01-01')], '2', 0.95, IdentificationStatus::Unresolved],
            'an album the embedded identifiers rule out' => [[self::embeddedAlbumA(), self::albumB()], '2', 0.95, IdentificationStatus::NeedsReview],
        ];
    }

    /**
     * @param  list<CandidateHypothesis>  $albums
     */
    #[Test]
    #[DataProvider('picksThatAreDeclined')]
    public function a_pick_that_is_not_linked_leaves_the_matcher_decision_and_is_logged_unapplied(array $albums, string $choice, float $probability, IdentificationStatus $matcher): void
    {
        $this->answer($choice, $probability);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates($albums));

        $this->assertNotNull($identification);
        $this->assertSame([$matcher, null], [$identification->state, $identification->musicbrainz_release_group_id]);
        $this->assertNotContains('decision_model_pick', array_column($identification->fresh()?->reasons ?? [], 'code'));
        $this->assertSame(1, $this->questionsAsked());
        $row = DecisionModelDecision::query()->sole();
        $this->assertSame([$identification->id, 'apply', false, null], [$row->subject_id, $row->mode, $row->applied, $row->failure_reason]);
    }

    /**
     * @return array<string, array{list<CandidateHypothesis>, IdentificationStatus}>
     */
    public static function decisionsThatAreNotAsked(): array
    {
        $full = ['First Light', 'Last Light'];
        $recording = new CandidateSignal(CandidateSignalKind::TrackEvidenceSearch, 'search-result-0', 'search-file:0', false, new CandidateIdentity(recordingId: '14141414-1414-4141-8141-000000000001', releaseGroupId: self::GROUP_A));
        $release = new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_A, 'tag', true, new CandidateIdentity(releaseId: self::RELEASE_A, releaseGroupId: self::GROUP_A));

        return [
            'conflicted' => [[self::embeddedAlbumA(), PickCandidates::album(self::RELEASE_B, self::GROUP_B, 'Example Album (Deluxe)', 'Example Artist', '2021-05-05', [PickCandidates::embeddedGroup(self::GROUP_B)])], IdentificationStatus::Conflicted],
            'an accepted recording' => [[PickCandidates::album(self::RELEASE_A, self::GROUP_A, 'Example Album', 'Example Artist', '2020-01-01', [$recording], $full), self::albumB()], IdentificationStatus::AcceptedRecording],
            'an accepted album' => [[PickCandidates::album(self::RELEASE_A, self::GROUP_A, 'Example Album', 'Example Artist', '2020-01-01', [$release], $full), self::albumB()], IdentificationStatus::AcceptedEdition],
        ];
    }

    /**
     * @param  list<CandidateHypothesis>  $albums
     */
    #[Test]
    #[DataProvider('decisionsThatAreNotAsked')]
    public function a_decision_the_matcher_did_not_leave_undecided_is_not_asked(array $albums, IdentificationStatus $matcher): void
    {
        $this->answer('2', 0.95);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates($albums));

        $this->assertSame($matcher, $identification?->state);
        $this->assertSame(0, $this->questionsAsked());
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    #[Test]
    public function a_release_under_ninety_five_percent_complete_or_a_disabled_client_is_not_asked(): void
    {
        $this->answer('2', 0.95);
        $incomplete = $this->release(completion: 94.99);
        $this->evidence($incomplete);
        $this->assertSame(IdentificationStatus::Unresolved, $this->resolve($incomplete, new PickCandidates([self::albumA(), self::albumB()]))?->state);

        config(['decision-model.enabled' => false]);
        $complete = $this->release();
        $this->evidence($complete);
        $this->assertSame(IdentificationStatus::Unresolved, $this->resolve($complete, new PickCandidates([self::albumA(), self::albumB()]))?->state);

        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    #[Test]
    public function an_answer_the_service_rejects_leaves_the_matcher_decision_and_is_logged_with_its_reason(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['nothing' => 'here']), 'https://caa.test/*' => Http::response('', 404)]);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates([self::albumA(), self::albumB()]));

        $this->assertSame(IdentificationStatus::Unresolved, $identification?->state);
        $row = DecisionModelDecision::query()->sole();
        $this->assertSame([$identification?->id, 'apply', false, 'malformed_response', null], [$row->subject_id, $row->mode, $row->applied, $row->failure_reason, $row->interpretation]);
    }

    #[Test]
    public function an_unavailable_service_leaves_the_matcher_decision_and_logs_nothing(): void
    {
        Http::fake([self::ENDPOINT => Http::response('down', 503)]);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates([self::albumA(), self::albumB()]));

        $this->assertSame(IdentificationStatus::Unresolved, $identification?->state);
        $this->assertSame(1, $this->questionsAsked());
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    #[Test]
    public function a_linked_album_whose_genre_lookup_fails_is_retried_and_logs_nothing(): void
    {
        $this->answer('2', 0.91);
        $release = $this->release();
        $this->evidence($release);

        $identification = $this->resolve($release, new PickCandidates([self::albumA(), self::albumB()]), new PickGenreGateway(fails: true));

        $this->assertSame(IdentificationStatus::RetryableError, $identification?->state);
        $this->assertSame(1, $this->questionsAsked());
        $this->assertSame(0, DecisionModelDecision::query()->count());
    }

    #[Test]
    public function a_linked_album_whose_lease_is_lost_during_resolution_logs_nothing(): void
    {
        Http::fake([self::ENDPOINT => function (): PromiseInterface {
            // Another worker takes the lease while the model is being asked.
            DB::table('release_music_identifications')->update(['lease_token' => 'worker-b']);

            return Http::response(['answers' => ['album' => ['choice' => '2', 'probabilities' => ['2' => 0.91], 'confidence' => 0.91]]]);
        }]);
        $release = $this->release();
        $this->evidence($release);

        $this->assertNull($this->resolve($release, new PickCandidates([self::albumA(), self::albumB()])));

        $this->assertSame(1, $this->questionsAsked());
        $this->assertSame(0, DecisionModelDecision::query()->count());
        $this->assertSame(IdentificationStatus::Pending->value, DB::table('release_music_identifications')->value('state'));
    }

    private static function albumA(): CandidateHypothesis
    {
        return PickCandidates::album(self::RELEASE_A, self::GROUP_A, 'Example Album', 'Example Artist', '2020-01-01');
    }

    /** Album A, whose release group the release's tags name: no other group fits the embedded identifier. */
    private static function embeddedAlbumA(): CandidateHypothesis
    {
        return PickCandidates::album(self::RELEASE_A, self::GROUP_A, 'Example Album', 'Example Artist', '2020-01-01', [PickCandidates::embeddedGroup(self::GROUP_A)]);
    }

    private static function albumB(): CandidateHypothesis
    {
        return PickCandidates::album(self::RELEASE_B, self::GROUP_B, 'Example Album (Deluxe)', 'Example Artist', '2021-05-05');
    }

    private function answer(string $choice, float $probability): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['answers' => ['album' => ['choice' => $choice, 'probabilities' => [$choice => $probability], 'confidence' => $probability]]]),
            'https://caa.test/*' => Http::response('', 404),
        ]);
    }

    /** How many requests went to the decision model. */
    private function questionsAsked(): int
    {
        return Http::recorded(static fn (Request $request): bool => $request->url() === self::ENDPOINT)->count();
    }

    /** @return list<string|null> the release groups of a decision's stored candidates, by rank */
    private function storedCandidateGroups(int $identificationId): array
    {
        return DB::table('release_music_candidate_attempts')->where('release_music_identification_id', $identificationId)->orderBy('rank')->pluck('musicbrainz_release_group_id')->all();
    }

    /** @return list<string> a release group's stored genre names, in position order */
    private function genresOf(string $releaseGroupId): array
    {
        return DB::table('musicbrainz_release_group_genres as g')
            ->join('audio_genres as a', 'a.id', '=', 'g.audio_genres_id')
            ->where('g.musicbrainz_release_group_id', $releaseGroupId)
            ->orderBy('g.position')
            ->pluck('a.name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function resolve(Release $release, CandidateGenerator $candidates, ?MusicBrainzGateway $musicBrainz = null): ?ReleaseMusicIdentification
    {
        $this->app->instance(CandidateGenerator::class, $candidates);
        $this->app->instance(MusicBrainzGateway::class, $musicBrainz ?? new PickGenreGateway);

        return app(ResolveReleaseMusicIdentity::class)->resolveRelease($release, 'worker-a');
    }

    private function release(float $completion = 100.0): Release
    {
        $id = DB::table('releases')->insertGetId([
            'guid' => bin2hex(random_bytes(16)),
            'leftguid' => 'a',
            'name' => 'Example Artist - Example Album',
            'searchname' => 'Example Artist - Example Album FLAC',
            'groups_id' => 1,
            'categories_id' => 3040,
            'completion' => $completion,
            'postdate' => now(),
        ]);

        return Release::query()->findOrFail($id);
    }

    /** Two tagged tracks of Example Album by Example Artist. */
    private function evidence(Release $release): ReleaseAudioEvidence
    {
        $evidence = ReleaseAudioEvidence::query()->create([
            'releases_id' => $release->id,
            'revision' => 1,
            'evidence_hash' => hash('sha256', 'evidence-'.$release->id),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => ['name' => $release->name, 'searchname' => $release->searchname],
            'archive_manifest_complete' => null,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ]);
        foreach (['First Light', 'Last Light'] as $index => $title) {
            $evidence->tracks()->create([
                'source_kind' => 'archive',
                'source_ordinal' => $index + 1,
                'raw_filename' => sprintf('%02d - %s.flac', $index + 1, $title),
                'track_number' => $index + 1,
                'album' => 'Example Album',
                'album_artist' => 'Example Artist',
                'title' => $title,
                'whole_duration_seconds' => $index === 0 ? 180 : 210,
                'whole_duration_reliable' => true,
            ]);
        }

        return $evidence;
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

/** The matcher's candidate pool, fixed. */
final readonly class PickCandidates implements CandidateGenerator
{
    /** @param list<CandidateHypothesis> $albums */
    public function __construct(private array $albums) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        return new CandidatePool($this->albums);
    }

    /**
     * An edition with two tracks, the first of which the evidence carries.
     *
     * @param  list<CandidateSignal>  $signals
     * @param  list<string>  $trackTitles
     */
    public static function album(string $releaseId, ?string $releaseGroupId, string $title, string $artist, string $date, array $signals = [], array $trackTitles = ['First Light', 'Nowhere']): CandidateHypothesis
    {
        $releaseTracks = [];
        foreach ($trackTitles as $index => $trackTitle) {
            $releaseTracks[] = [
                'musicBrainzReleaseTrackId' => sprintf('%s-%04d', substr($releaseId, 0, 31), $index + 1),
                'title' => $trackTitle, 'position' => $index + 1, 'number' => (string) ($index + 1), 'lengthMs' => $index === 0 ? 180_000 : 210_000,
                'artistCredit' => $artist, 'recording' => null,
            ];
        }
        $releaseGroups = $releaseGroupId === null ? [] : [[
            'releaseGroupId' => $releaseGroupId, 'title' => $title, 'artistCredit' => $artist, 'primaryType' => 'Album',
            'secondaryTypes' => [], 'firstReleaseDate' => $date, 'aliases' => [],
        ]];

        return new CandidateHypothesis(
            new CandidateIdentity(releaseId: $releaseId, releaseGroupId: $releaseGroupId),
            new CandidateMetadata([], [[
                'releaseId' => $releaseId, 'title' => $title, 'artistCredit' => $artist, 'releaseGroupId' => $releaseGroupId,
                'status' => 'Official', 'date' => $date, 'country' => 'GB', 'barcode' => null, 'labels' => [], 'aliases' => [],
                'media' => [['position' => 1, 'title' => null, 'format' => 'CD', 'releaseTrackCount' => 2, 'discIds' => [], 'releaseTracks' => $releaseTracks]],
            ]], $releaseGroups),
            $signals,
        );
    }

    public static function embeddedGroup(string $releaseGroupId): CandidateSignal
    {
        return new CandidateSignal(CandidateSignalKind::EmbeddedReleaseGroupId, $releaseGroupId, 'tag', true, new CandidateIdentity(releaseGroupId: $releaseGroupId));
    }
}

/** MusicBrainz answering only the accepted album's release-group lookup, with two genres. */
final class PickGenreGateway implements MusicBrainzGateway
{
    public function __construct(private readonly bool $fails = false) {}

    public function candidatesFor(RecordingQuery $query): RecordingCandidates
    {
        return RecordingCandidates::empty();
    }

    public function releaseCandidatesFor(ReleaseQuery $query): ReleaseCandidates
    {
        return ReleaseCandidates::empty();
    }

    public function releaseGroupCandidatesFor(ReleaseGroupQuery $query): ReleaseGroupCandidates
    {
        return ReleaseGroupCandidates::empty();
    }

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata
    {
        return CandidateMetadata::empty();
    }

    public function releaseGroup(string $releaseGroupId): ?array
    {
        if ($this->fails) {
            throw new MusicBrainzGatewayException('MusicBrainz returned HTTP 503.');
        }

        return [
            'releaseGroupId' => $releaseGroupId, 'title' => 'Example Album', 'artistCredit' => 'Example Artist',
            'primaryType' => 'Album', 'secondaryTypes' => [], 'firstReleaseDate' => null, 'aliases' => [],
            'genres' => [['name' => 'Ambient', 'count' => 3], ['name' => 'Electronic', 'count' => 5]],
        ];
    }
}
