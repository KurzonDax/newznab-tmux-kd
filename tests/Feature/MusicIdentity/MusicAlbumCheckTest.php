<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Models\DecisionModelCriteria;
use App\Models\DecisionModelDecision;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheck;
use App\Services\MusicIdentity\DecisionModel\MusicAlbumCheckUseCase;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The music pass's shadow check of the matcher's album choices (issue #1140):
 * which decisions are asked, in what order and how many per pass, what the
 * service's failures do, and that nothing but the decision log changes.
 */
final class MusicAlbumCheckTest extends TestCase
{
    private const string ENDPOINT = 'https://decision.example.test/v1/systemone';

    private const string GROUP_A = '11111111-1111-4111-8111-111111111111';

    private const string GROUP_B = '22222222-2222-4222-8222-222222222222';

    private int $nextReleaseId = 100;

    /** @var list<array<string, mixed>> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'music-identity.algorithm_version' => 'music-identity-v1',
            'decision-model.enabled' => true,
            'decision-model.base_url' => 'https://decision.example.test',
            'decision-model.api_key' => 'invented-token',
            'decision-model.retry_attempts' => 1,
            'decision-model.circuit_breaker_failures' => 3,
        ]);
        DB::purge();
        DB::reconnect();

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('releases', ['id', 'searchname', 'categories_id', 'musicinfo_id']);
        $tables->create('release_files', ['releases_id', 'name']);
        $tables->create('decision_model_criteria', null);
        $tables->create('decision_model_decisions', null);
        foreach ([
            '*_create_release_audio_evidence_tables.php',
            '*_create_release_music_identification_tables.php',
            '*_add_accepted_music_text_to_release_music_identifications.php',
            '*_create_musicbrainz_release_group_genres_table.php',
            '*_create_musicbrainz_release_tracks_table.php',
            '*_create_musicbrainz_artist_tables.php',
            '*_create_release_music_renames_table.php',
            '*_create_music_cover_art_lookups_table.php',
        ] as $pattern) {
            $this->migration($pattern)->up();
        }
        DB::table('settings')->insert([
            ['name' => 'music_identity_enabled', 'value' => '1'],
            ['name' => MusicAlbumCheck::SETTING, 'value' => 'shadow'],
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if ($event->message === 'music_identity.album_check_unavailable') {
                $this->warnings[] = $event->context;
            }
        });
    }

    #[Test]
    public function it_asks_the_current_completed_decisions_with_a_titled_candidate_once_per_criteria_version(): void
    {
        $this->answer('1', 0.9);
        $a = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $b = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);
        $c = $this->decision(IdentificationStatus::NeedsReview, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->decision(IdentificationStatus::AcceptedRecording, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->decision(IdentificationStatus::Unresolved, []);
        $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, null, null, null], [null, 'Lenka', '', '2026']]);
        // A superseded older decision of release (a): same evidence, an older algorithm version.
        $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_B, 'Train', 'Mad Dog in the Fog', '2026']], releaseId: $this->releaseOf($a), version: 'music-identity-v0');
        $g = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->logged($g, app(MusicAlbumCheckUseCase::class)->criteriaVersion());
        $h = $this->decision(IdentificationStatus::Conflicted, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->logged($h, 'v0 invented: an earlier question');
        $seeded = (int) DecisionModelDecision::query()->max('id');

        $this->assertSame(4, $this->check());

        Http::assertSentCount(4);
        $this->assertSame([$h, $c, $b, $a], $this->loggedSubjects(after: $seeded));
        $this->assertSame(0, $this->check(), 'everything current is logged');
        Http::assertSentCount(4);
    }

    #[Test]
    public function a_logged_row_holds_the_state_the_matcher_answer_and_the_verdict(): void
    {
        $this->answer('2', 0.91);
        $id = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [
            [self::GROUP_A, 'Lenka', 'Good Days', '2026-03-13'],
            [self::GROUP_B, 'Lenka', 'Good Days (Deluxe)', '2026-11-01'],
        ], acceptedGroup: self::GROUP_A, searchname: 'Lenka - Good Days (2026) FLAC');
        DB::table('release_files')->insert([
            ['releases_id' => $this->releaseOf($id), 'name' => 'MP3\\02 - Lenka - Enjoy.mp3'],
            ['releases_id' => $this->releaseOf($id), 'name' => 'MP3/01 - Lenka - Good Days.mp3'],
        ]);

        $this->check();

        $row = DecisionModelDecision::query()->with('criteria')->sole();
        $this->assertSame('music_album_check', $row->criteria?->use_case);
        $this->assertSame(app(MusicAlbumCheckUseCase::class)->criteriaVersion(), $row->criteria?->version);
        $this->assertSame(['release_music_identification', $id, 'shadow', false, null], [
            $row->subject_type, $row->subject_id, $row->mode, $row->applied, $row->failure_reason,
        ]);
        $this->assertSame(
            ['release_name' => 'Lenka - Good Days (2026) FLAC', 'files' => ['01 - Lenka - Good Days.mp3', '02 - Lenka - Enjoy.mp3']],
            $row->state,
        );
        $this->assertSame([['album' => ['choice' => '2', 'probabilities' => ['2' => 0.91], 'confidence' => 0.91]]], $row->answers);
        $this->assertSame([
            'state' => 'accepted_release_group',
            'band' => 'strong',
            'score' => 96,
            'accepted_release_group_id' => self::GROUP_A,
            'candidate_count' => 2,
            'untitled_candidates' => 0,
            'options' => [
                '1' => ['rank' => 1, 'release_group_id' => self::GROUP_A, 'label' => 'Lenka - Good Days (2026)'],
                '2' => ['rank' => 2, 'release_group_id' => self::GROUP_B, 'label' => 'Lenka - Good Days (Deluxe) (2026)'],
            ],
            'matcher_renamed' => false,
            'releases_id' => $this->releaseOf($id),
        ], $row->pipeline_answer);
        $this->assertSame([
            'choice' => '2',
            'probability' => 0.91,
            'picked_rank' => 2,
            'picked_release_group_id' => self::GROUP_B,
            'would_link' => true,
            'link_threshold' => 0.8,
            'agrees_with_accepted' => false,
        ], $row->interpretation);
    }

    #[Test]
    public function a_pass_logs_at_most_fifty_decisions_newest_first_and_the_next_pass_takes_the_rest(): void
    {
        $this->answer('none', 0.7);
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $ids[] = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        }
        rsort($ids);

        $this->assertSame(50, $this->check());
        Http::assertSentCount(50);
        $this->assertSame(array_slice($ids, 0, 50), $this->loggedSubjects());

        $this->assertSame(10, $this->check());
        Http::assertSentCount(60);
        $this->assertSame($ids, $this->loggedSubjects());
    }

    #[Test]
    public function a_service_failure_ends_the_pass_and_the_next_pass_asks_the_same_decision_again(): void
    {
        $first = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $second = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['answers' => ['album' => $this->albumAnswer('1', 0.9)]])
            ->push('down', 503)
            ->push(['answers' => ['album' => $this->albumAnswer('none', 0.6)]]),
        ]);

        $this->assertSame(1, $this->check());

        Http::assertSentCount(2);
        $this->assertSame([$second], $this->loggedSubjects());
        $this->assertSame([['identification_id' => $first, 'reason' => 'http_503']], $this->warnings);

        $this->assertSame(1, $this->check());
        $this->assertSame([$second, $first], $this->loggedSubjects());
        $this->assertSame('none', DecisionModelDecision::query()->where('subject_id', $first)->sole()->interpretation['choice'] ?? null);
    }

    #[Test]
    public function an_unusable_answer_is_logged_with_its_reason_and_not_asked_again(): void
    {
        $id = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        Http::fake([self::ENDPOINT => Http::response(['answers' => ['album' => ['error' => 'too_long', 'message' => 'The question exceeds the token cap.']]])]);

        $this->assertSame(1, $this->check());
        $this->assertSame(0, $this->check());

        Http::assertSentCount(1);
        $row = DecisionModelDecision::query()->sole();
        $this->assertSame([$id, 'album_answer_unusable', null], [$row->subject_id, $row->failure_reason, $row->interpretation]);
        $this->assertSame('too_long', $row->answers[0]['album']['error'] ?? null);
        $this->assertSame([], $this->warnings);
    }

    #[Test]
    public function a_rejected_request_is_logged_with_its_status_and_the_pass_goes_on(): void
    {
        $first = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $second = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $third = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['answers' => ['album' => $this->albumAnswer('1', 0.9)]])
            ->push('bad body', 422)
            ->push(['answers' => ['album' => $this->albumAnswer('1', 0.9)]]),
        ]);

        $this->assertSame(3, $this->check());

        Http::assertSentCount(3);
        $this->assertSame([$third, $second, $first], $this->loggedSubjects());
        $this->assertSame(
            [null, 'http_422', null],
            DecisionModelDecision::query()->orderByDesc('subject_id')->pluck('failure_reason')->all(),
        );
        $this->assertSame(0, $this->check(), 'the rejected decision is not asked again');
        Http::assertSentCount(3);
        $this->assertSame([], $this->warnings);
    }

    #[Test]
    public function a_refused_key_records_nothing_and_ends_the_pass(): void
    {
        $id = $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);
        Http::fake([self::ENDPOINT => Http::response('no', 401)]);

        $this->assertSame(0, $this->check());

        Http::assertSentCount(1);
        $this->assertSame(0, DecisionModelDecision::query()->count());
        $this->assertSame('http_401', $this->warnings[0]['reason'] ?? null);
        $this->assertSame($id + 1, $this->warnings[0]['identification_id'] ?? null, 'the newest decision was the one asked');
    }

    #[Test]
    public function off_or_a_disabled_client_sends_nothing_and_writes_nothing(): void
    {
        Http::fake();
        $this->decision(IdentificationStatus::Unresolved, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']]);

        DB::table('settings')->where('name', MusicAlbumCheck::SETTING)->update(['value' => 'off']);
        $this->assertSame(0, $this->check());

        DB::table('settings')->where('name', MusicAlbumCheck::SETTING)->update(['value' => 'apply']);
        $this->assertSame(0, $this->check(), 'a stored mode the setting does not offer is off');

        DB::table('settings')->where('name', MusicAlbumCheck::SETTING)->update(['value' => 'shadow']);
        config(['decision-model.enabled' => false]);
        $this->assertSame(0, $this->check());

        Http::assertNothingSent();
        $this->assertSame(0, DecisionModelDecision::query()->count());
        $this->assertSame(0, DecisionModelCriteria::query()->count());
    }

    #[Test]
    public function a_release_renamed_by_the_matcher_is_marked(): void
    {
        $this->answer('1', 0.9);
        $applied = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);
        $declined = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);
        $reverted = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);
        $none = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [[self::GROUP_A, 'Lenka', 'Good Days', '2026']], acceptedGroup: self::GROUP_A);
        $this->rename($applied, 'applied');
        $this->rename($declined, 'declined');
        $this->rename($reverted, 'applied', reverted: true);

        $this->check();

        $marked = DecisionModelDecision::query()->get()->mapWithKeys(static fn (DecisionModelDecision $row): array => [$row->subject_id => $row->pipeline_answer['matcher_renamed'] ?? null])->all();
        $this->assertSame([$applied => true, $declined => false, $reverted => false, $none => false], [
            $applied => $marked[$applied], $declined => $marked[$declined], $reverted => $marked[$reverted], $none => $marked[$none],
        ]);
    }

    #[Test]
    public function shadow_changes_nothing_but_the_decision_log(): void
    {
        $this->answer('2', 0.97);
        $id = $this->decision(IdentificationStatus::AcceptedReleaseGroup, [
            [self::GROUP_A, 'Lenka', 'Good Days', '2026'],
            [self::GROUP_B, 'Train', 'Mad Dog in the Fog', '2026'],
        ], acceptedGroup: self::GROUP_A, searchname: 'Lenka - Good Days (2026) FLAC');
        $this->rename($id, 'applied');
        DB::table('music_cover_art_lookups')->insert([
            'kind' => 'release-group', 'musicbrainz_id' => self::GROUP_A, 'outcome' => 'stored', 'image_musicbrainz_id' => self::GROUP_A,
            'attempt_count' => 1, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->everythingButTheLog();

        $this->assertSame(1, $this->check());

        $this->assertSame(1, DecisionModelDecision::query()->count());
        $this->assertFalse(DecisionModelDecision::query()->sole()->interpretation['agrees_with_accepted'] ?? null);
        $this->assertEquals($before, $this->everythingButTheLog());
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function everythingButTheLog(): array
    {
        $tables = ['releases', 'release_files', 'release_audio_evidence', 'release_music_identifications', 'release_music_candidate_attempts', 'release_music_renames', 'music_cover_art_lookups'];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->orderByRaw('1')->get()->all();
        }

        return $rows;
    }

    private function check(): int
    {
        return app(MusicAlbumCheck::class)->catchUp();
    }

    /**
     * @return list<int> subject ids in the order the decisions were logged
     */
    private function loggedSubjects(int $after = 0): array
    {
        return DecisionModelDecision::query()->where('id', '>', $after)->orderBy('id')->pluck('subject_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function answer(string $choice, float $probability): void
    {
        Http::fake([self::ENDPOINT => Http::response(['answers' => ['album' => $this->albumAnswer($choice, $probability)]])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function albumAnswer(string $choice, float $probability): array
    {
        return ['choice' => $choice, 'probabilities' => [$choice => $probability], 'confidence' => $probability];
    }

    /**
     * A completed matcher decision with stored candidates, on a release of its own unless one
     * is given; current under the configured version unless an older version is given.
     *
     * @param  list<array{?string, ?string, ?string, ?string}>  $candidates  release group, artist credit, title, date, in rank order
     * @return int the identification id
     */
    private function decision(
        IdentificationStatus $state,
        array $candidates,
        ?string $acceptedGroup = null,
        ?int $releaseId = null,
        string $version = 'music-identity-v1',
        string $searchname = 'Lenka - Good Days (2026) FLAC',
    ): int {
        if ($releaseId === null) {
            $releaseId = $this->nextReleaseId++;
            DB::table('releases')->insert(['id' => $releaseId, 'searchname' => $searchname, 'categories_id' => 3040, 'musicinfo_id' => null]);
            DB::table('release_audio_evidence')->insert([
                'releases_id' => $releaseId, 'revision' => 1, 'evidence_hash' => hash('sha256', (string) $releaseId), 'schema_version' => 1,
                'provenance' => 'captured', 'release_snapshot' => '[]', 'archive_manifest_complete' => 1, 'nzb_manifest' => '[]',
                'archive_manifest' => '[]', 'sidecar_manifest' => '[]', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $evidence = DB::table('release_audio_evidence')->where('releases_id', $releaseId)->orderByDesc('revision')->first();
        $this->assertNotNull($evidence);

        $id = DB::table('release_music_identifications')->insertGetId([
            'releases_id' => $releaseId,
            'release_audio_evidence_id' => $evidence->id,
            'evidence_hash' => $evidence->evidence_hash,
            'state' => $state->value,
            'score' => $state->acceptsAlbum() ? 96 : 40,
            'band' => $state->acceptsAlbum() ? 'strong' : 'unresolved',
            'accepted_scope' => $state->acceptsAlbum() ? 'release_group' : null,
            'musicbrainz_release_group_id' => $acceptedGroup,
            'reasons' => '[]',
            'feature_contributions' => '{}',
            'attempt_count' => 1,
            'algorithm_version' => $version,
            'resolver_version' => 'resolver-v1',
            'normalizer_version' => 'normalizer-v1',
            'scorer_version' => 'whole-release-v1',
            'policy_version' => 'shadow-v1',
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($candidates as $rank => [$group, $artistCredit, $title, $date]) {
            DB::table('release_music_candidate_attempts')->insert([
                'release_music_identification_id' => $id,
                'rank' => $rank + 1,
                'score' => 90 - $rank,
                'musicbrainz_release_group_id' => $group,
                'display_snapshot' => json_encode(['title' => $title, 'artistCredit' => $artistCredit, 'date' => $date, 'country' => null], JSON_THROW_ON_ERROR),
                'feature_vector' => '{}',
                'score_contributions' => '{}',
                'contradictions' => '[]',
                'provenance' => '["fixture"]',
                'response_cache_keys' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function releaseOf(int $identificationId): int
    {
        return (int) DB::table('release_music_identifications')->where('id', $identificationId)->value('releases_id');
    }

    private function rename(int $identificationId, string $outcome, bool $reverted = false): void
    {
        DB::table('release_music_renames')->insert([
            'releases_id' => $this->releaseOf($identificationId),
            'release_music_identification_id' => $identificationId,
            'outcome' => $reverted ? 'reverted' : $outcome,
            'reason' => $outcome === 'declined' ? 'no_accepted_evaluation' : null,
            'before' => $outcome === 'applied' ? '{"searchname":"Invented Name"}' : null,
            'after' => $outcome === 'applied' ? '{"searchname":"Lenka - Good Days (2026) FLAC"}' : null,
            'applied_at' => $outcome === 'applied' ? now() : null,
            'reverted_at' => $reverted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A decision row already logged for the identification under the given criteria version. */
    private function logged(int $identificationId, string $version): void
    {
        $criteria = DecisionModelCriteria::query()->firstOrCreate(['use_case' => MusicAlbumCheckUseCase::NAME, 'version' => $version]);
        DecisionModelDecision::query()->create([
            'decision_model_criteria_id' => $criteria->id,
            'subject_type' => MusicAlbumCheck::SUBJECT_TYPE,
            'subject_id' => $identificationId,
            'mode' => 'shadow',
            'state' => 'Lenka - Good Days (2026) FLAC',
            'applied' => false,
        ]);
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
