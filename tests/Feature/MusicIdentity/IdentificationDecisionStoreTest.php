<?php

declare(strict_types=1);

namespace Tests\Feature\MusicIdentity;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateSummary;
use App\Services\MusicIdentity\DTO\DecisionReason;
use App\Services\MusicIdentity\DTO\IdentificationDecision;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\LostMusicIdentityLease;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\Persistence\MusicIdentityLeaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IdentificationDecisionStoreTest extends TestCase
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
        });
        Schema::create('release_audio_evidence', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->char('evidence_hash', 64);
        });
        $this->identificationMigration()->up();

        DB::table('releases')->insert(['id' => 10]);
        DB::table('release_audio_evidence')->insert([
            'id' => 20,
            'releases_id' => 10,
            'evidence_hash' => str_repeat('a', 64),
        ]);
    }

    #[Test]
    public function it_persists_an_explained_decision_and_a_bounded_ranked_candidate_snapshot(): void
    {
        $decision = $this->decision('music-identity-v1', candidateCount: 4);

        $identification = (new IdentificationDecisionStore(candidateAttemptLimit: 2))
            ->persist(10, $this->evidence(), $decision);

        $this->assertSame(IdentificationStatus::AcceptedEdition, $identification->state);
        $this->assertSame(IdentificationBand::Verified, $identification->band);
        $this->assertSame('edition', $identification->accepted_scope?->value);
        $this->assertSame('release-1', $identification->musicbrainz_release_id);
        $this->assertSame('release-group-1', $identification->musicbrainz_release_group_id);
        $this->assertSame('structural_gate_passed', $identification->reasons[0]['code']);
        $this->assertSame(['exact_identifier_agreement' => 40], $identification->feature_contributions);
        $this->assertSame([1, 2], $identification->candidateAttempts()->pluck('rank')->all());
        $this->assertSame(['tag-file:1'], $identification->candidateAttempts()->firstOrFail()->provenance);
    }

    #[Test]
    public function the_container_uses_the_configured_candidate_attempt_limit(): void
    {
        config(['music-identity.candidate_attempt_limit' => 1]);

        $identification = $this->app->make(IdentificationDecisionStore::class)->persist(
            10,
            $this->evidence(),
            $this->decision('music-identity-v1', candidateCount: 4),
        );

        $this->assertCount(1, $identification->candidateAttempts);
    }

    #[Test]
    public function the_same_release_evidence_and_algorithm_is_idempotent_and_does_not_rewrite_the_conclusion(): void
    {
        $store = new IdentificationDecisionStore;
        $first = $store->persist(10, $this->evidence(), $this->decision('music-identity-v1'));
        $differentConclusion = $this->decision('music-identity-v1', IdentificationStatus::NeedsReview);

        $second = $store->persist(10, $this->evidence(), $differentConclusion);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(IdentificationStatus::AcceptedEdition, $second->state);
        $this->assertSame(1, ReleaseMusicIdentification::query()->count());
    }

    #[Test]
    public function a_new_algorithm_version_appends_a_row_that_supersedes_the_previous_conclusion(): void
    {
        $store = new IdentificationDecisionStore;
        $first = $store->persist(10, $this->evidence(), $this->decision('music-identity-v1'));
        $second = $store->persist(10, $this->evidence(), $this->decision('music-identity-v2'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame(2, ReleaseMusicIdentification::query()->count());
    }

    #[Test]
    public function a_retryable_attempt_can_transition_to_a_terminal_decision_without_freezing_the_error(): void
    {
        $store = new IdentificationDecisionStore;
        $retryAt = now()->addMinutes(5);
        $retryable = $this->decision(
            'music-identity-v1',
            IdentificationStatus::RetryableError,
            candidateCount: 0,
            operationalError: 'mirror unavailable',
        );

        $first = $store->persist(10, $this->evidence(), $retryable, $retryAt);
        $second = $store->persist(10, $this->evidence(), $this->decision('music-identity-v1'));

        $this->assertNull($first->decided_at);
        $this->assertSame($retryAt->getTimestamp(), $first->next_attempt_at?->getTimestamp());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->attempt_count);
        $this->assertSame(IdentificationStatus::AcceptedEdition, $second->state);
        $this->assertNull($second->last_operational_error);
        $this->assertNull($second->next_attempt_at);
        $this->assertNotNull($second->decided_at);
        $this->assertCount(1, $second->candidateAttempts);
    }

    #[Test]
    public function an_expired_lease_cannot_persist_a_pending_decision(): void
    {
        Carbon::setTestNow('2026-08-30 12:00:00');
        config([
            'music-identity.algorithm_version' => 'music-identity-v1',
            'music-identity.lease_seconds' => 60,
        ]);
        $evidenceRecord = ReleaseAudioEvidence::query()->findOrFail(20);
        $lease = (new MusicIdentityLeaseManager)->acquire($evidenceRecord, 'worker-a');
        $this->assertNotNull($lease);
        Carbon::setTestNow(now()->addSeconds(61));

        try {
            (new IdentificationDecisionStore)->persist(
                10,
                $this->evidence(),
                $this->decision('music-identity-v1'),
                leaseToken: 'worker-a',
            );
            $this->fail('An expired worker lease unexpectedly persisted a decision.');
        } catch (LostMusicIdentityLease) {
            $this->addToAssertionCount(1);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function evidence_ownership_and_hash_are_validated_before_a_decision_is_written(): void
    {
        $wrongEvidence = new AudioEvidenceSet(
            evidenceId: 20,
            evidenceHash: str_repeat('b', 64),
            releaseTitle: 'Example',
            albumTitle: 'Example',
            albumArtist: 'Artist',
            releaseYear: 2020,
            trackEvidence: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        (new IdentificationDecisionStore)->persist(10, $wrongEvidence, $this->decision('music-identity-v1'));
    }

    private function evidence(): AudioEvidenceSet
    {
        return new AudioEvidenceSet(
            evidenceId: 20,
            evidenceHash: str_repeat('a', 64),
            releaseTitle: 'Example',
            albumTitle: 'Example',
            albumArtist: 'Artist',
            releaseYear: 2020,
            trackEvidence: [],
        );
    }

    private function decision(
        string $algorithmVersion,
        IdentificationStatus $status = IdentificationStatus::AcceptedEdition,
        int $candidateCount = 1,
        ?string $operationalError = null,
    ): IdentificationDecision {
        $candidates = [];
        for ($rank = 1; $rank <= $candidateCount; $rank++) {
            $candidates[] = new CandidateSummary(
                identity: new CandidateIdentity(
                    recordingId: 'recording-'.$rank,
                    releaseId: 'release-'.$rank,
                    releaseGroupId: 'release-group-'.$rank,
                ),
                score: 101 - $rank,
                displaySnapshot: ['title' => 'Candidate '.$rank],
                featureVector: ['observed_coverage' => 1.0],
                scoreContributions: ['exact_identifier_agreement' => 40],
                contradictions: [],
                provenanceFamilies: ['tag-file:'.$rank],
                responseCacheKeys: ['musicbrainz:release:'.$rank],
            );
        }

        return new IdentificationDecision(
            status: $status,
            score: 100,
            band: IdentificationBand::Verified,
            acceptedIdentity: $status === IdentificationStatus::AcceptedEdition
                ? new CandidateIdentity(recordingId: 'recording-1', releaseId: 'release-1', releaseGroupId: 'release-group-1')
                : null,
            reasons: [new DecisionReason('structural_gate_passed', 'The candidate passed.', 100)],
            candidates: $candidates,
            runnerUpMargin: 8,
            algorithmVersion: $algorithmVersion,
            resolverVersion: 'resolver-v1',
            normalizerVersion: 'normalizer-v1',
            scorerVersion: 'scorer-v1',
            policyVersion: 'shadow-v1',
            operationalError: $operationalError,
        );
    }

    private function identificationMigration(): Migration
    {
        $paths = glob(database_path('migrations/*_create_release_music_identification_tables.php')) ?: [];
        $this->assertCount(1, $paths, 'The identification migration is missing.');

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
