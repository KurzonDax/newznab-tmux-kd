<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\CandidateHypothesis;
use App\Services\MusicIdentity\DTO\CandidateIdentity;
use App\Services\MusicIdentity\DTO\CandidateMetadata;
use App\Services\MusicIdentity\DTO\CandidatePool;
use App\Services\MusicIdentity\DTO\CandidateSignal;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Enums\CandidateSignalKind;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;
use App\Services\MusicIdentity\MusicIdentityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MusicIdentityResolverTest extends TestCase
{
    private const string RELEASE_ID = '11111111-1111-4111-8111-111111111111';

    private const string RELEASE_GROUP_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    #[Test]
    public function a_validated_embedded_release_mbid_accepts_the_matching_edition(): void
    {
        $evidence = $this->evidence([
            new TrackEvidence(1, 'tag', 1, '01 - First Light.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02 - Last Light.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ]);
        $candidate = $this->albumCandidate(
            titles: ['First Light', 'Last Light'],
            signals: [new CandidateSignal(
                CandidateSignalKind::EmbeddedReleaseId,
                self::RELEASE_ID,
                'tag-file:1',
                true,
                new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID),
            )],
        );

        $decision = (new MusicIdentityResolver(new FixedCandidateGenerator(new CandidatePool([$candidate]))))
            ->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
        $this->assertSame(IdentificationBand::Verified, $decision->band);
        $this->assertSame(self::RELEASE_ID, $decision->acceptedIdentity?->releaseId);
        $this->assertSame(self::RELEASE_GROUP_ID, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertGreaterThanOrEqual(97, $decision->score);
    }

    #[Test]
    public function an_invalid_embedded_release_mbid_is_not_treated_as_a_validated_contradiction(): void
    {
        $evidence = $this->evidence([
            new TrackEvidence(1, 'tag', 1, '01 - First Light.flac', 'First Light', 'Example Artist', 180_000, releaseId: 'not-an-mbid'),
        ]);

        $decision = $this->resolver([])->resolve($evidence);

        $this->assertSame(IdentificationStatus::Unresolved, $decision->status);
    }

    #[Test]
    public function validated_embedded_release_mbids_in_different_groups_are_conflicted(): void
    {
        $otherReleaseId = '99999999-9999-4999-8999-999999999999';
        $otherGroupId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $first = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true, new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID))],
        );
        $second = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, $otherReleaseId, 'tag-file:2', true, new CandidateIdentity(releaseId: $otherReleaseId, releaseGroupId: $otherGroupId))],
            releaseId: $otherReleaseId,
            releaseGroupId: $otherGroupId,
        );

        $decision = $this->resolver([$first, $second])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertSame('incompatible_embedded_release_ids', $decision->reasons[0]->code);
    }

    #[Test]
    public function validated_embedded_editions_in_one_group_collapse_to_group_scope(): void
    {
        $otherReleaseId = '99999999-9999-4999-8999-999999999999';
        $first = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true, new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID))],
        );
        $second = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, $otherReleaseId, 'tag-file:2', true, new CandidateIdentity(releaseId: $otherReleaseId, releaseGroupId: self::RELEASE_GROUP_ID))],
            releaseId: $otherReleaseId,
        );

        $decision = $this->resolver([$first, $second])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::RELEASE_GROUP_ID, $decision->acceptedIdentity?->releaseGroupId);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
    }

    #[Test]
    public function a_unique_exact_disc_id_with_compatible_structure_accepts_an_edition(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::DiscId, 'disc-1', 'cue:1', true, new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID))],
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'cue', 1, '01.flac', 'First Light', 'Example Artist', 180_000, discId: 'disc-1'),
            new TrackEvidence(2, 'cue', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, discId: 'disc-1'),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
    }

    #[Test]
    public function an_edition_scoped_hypothesis_cannot_align_to_a_better_matching_sibling_edition(): void
    {
        $siblingReleaseId = '44444444-4444-4444-8444-444444444444';
        $identity = new CandidateIdentity(releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $base = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::DiscId, 'disc-1', 'cue:1', true, $identity)],
            identity: $identity,
        );
        $identifiedRelease = $base->metadata->releases[0];
        $identifiedRelease['media'][0]['releaseTracks'][0]['title'] = 'Different One';
        $identifiedRelease['media'][0]['releaseTracks'][1]['title'] = 'Different Two';
        $siblingRelease = $base->metadata->releases[0];
        $siblingRelease['releaseId'] = $siblingReleaseId;
        $candidate = new CandidateHypothesis(
            $identity,
            new CandidateMetadata(
                $base->metadata->recordings,
                [$identifiedRelease, $siblingRelease],
                $base->metadata->releaseGroups,
            ),
            $base->signals,
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'cue', 1, '01.flac', 'First Light', 'Example Artist', 180_000, discId: 'disc-1'),
            new TrackEvidence(2, 'cue', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, discId: 'disc-1'),
        ], complete: false));

        $this->assertSame(self::RELEASE_ID, $decision->candidates[0]->identity->releaseId);
        $this->assertLessThan(1.0, $decision->candidates[0]->featureVector['release_track_title_agreement']);
    }

    #[Test]
    public function an_ambiguous_exact_disc_id_requires_review(): void
    {
        $signal = new CandidateSignal(CandidateSignalKind::DiscId, 'shared-disc', 'cue:1', true);
        $first = $this->albumCandidate(['First Light', 'Last Light'], [$signal]);
        $second = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [$signal],
            releaseId: '44444444-4444-4444-8444-444444444444',
            releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );

        $decision = $this->resolver([$first, $second])->resolve($this->evidence([
            new TrackEvidence(1, 'cue', 1, '01.flac', 'First Light', 'Example Artist', 180_000, discId: 'shared-disc'),
            new TrackEvidence(2, 'cue', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, discId: 'shared-disc'),
        ]));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertSame(0, $decision->runnerUpMargin);
    }

    #[Test]
    public function one_isrc_can_accept_only_the_supported_recording(): void
    {
        $recordingId = '55555555-5555-4555-8555-555555555555';
        $identity = new CandidateIdentity(recordingId: $recordingId, releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $candidate = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $identity)],
            identity: $identity,
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, isrc: 'USABC2012345'),
        ], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedRecording, $decision->status);
        $this->assertSame($recordingId, $decision->acceptedIdentity?->recordingId);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
        $this->assertNull($decision->acceptedIdentity?->releaseGroupId);
    }

    #[Test]
    public function ambiguous_isrc_recordings_require_review(): void
    {
        $firstIdentity = new CandidateIdentity(recordingId: 'recording-1', releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $secondIdentity = new CandidateIdentity(recordingId: 'recording-2', releaseId: '44444444-4444-4444-8444-444444444444', releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $first = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $firstIdentity)],
            identity: $firstIdentity,
        );
        $second = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $secondIdentity)],
            releaseId: $secondIdentity->releaseId,
            releaseGroupId: $secondIdentity->releaseGroupId,
            identity: $secondIdentity,
        );

        $decision = $this->resolver([$first, $second])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, isrc: 'USABC2012345'),
        ], complete: false));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertSame(0, $decision->runnerUpMargin);
    }

    #[Test]
    public function recording_matches_collapsed_under_one_release_remain_ambiguous(): void
    {
        $firstIdentity = new CandidateIdentity(recordingId: 'recording-1', releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $secondIdentity = new CandidateIdentity(recordingId: 'recording-2', releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $candidate = $this->albumCandidate(
            ['First Light'],
            [
                new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $firstIdentity),
                new CandidateSignal(CandidateSignalKind::Isrc, 'USABC2012345', 'tag-file:1', true, $secondIdentity),
            ],
            identity: $firstIdentity,
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, isrc: 'USABC2012345'),
        ], complete: false));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertNull($decision->acceptedIdentity);
    }

    #[Test]
    public function a_strong_single_track_text_result_can_accept_only_its_recording(): void
    {
        $recordingId = '55555555-5555-4555-8555-555555555555';
        $identity = new CandidateIdentity(recordingId: $recordingId, releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $candidate = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::TrackEvidenceSearch, 'first light', 'tag-file:1', false, $identity)],
            identity: $identity,
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedRecording, $decision->status);
        $this->assertSame($recordingId, $decision->acceptedIdentity?->recordingId);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
        $this->assertSame($recordingId, $decision->candidates[0]->identity->recordingId);
    }

    #[Test]
    public function three_distinct_recordings_with_ordered_agreement_accept_a_release_group(): void
    {
        $signals = [];
        foreach (range(1, 3) as $index) {
            $signals[] = new CandidateSignal(
                CandidateSignalKind::EmbeddedRecordingId,
                'recording-'.$index,
                'tag-file:'.$index,
                true,
                new CandidateIdentity(recordingId: 'recording-'.$index, releaseGroupId: self::RELEASE_GROUP_ID),
            );
        }
        $candidate = $this->albumCandidate(['Rare One', 'Rare Two', 'Rare Three'], $signals);

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'Rare One', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Rare Two', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Rare Three', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertNull($decision->acceptedIdentity?->recordingId);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
    }

    #[Test]
    public function correlated_recording_observations_do_not_count_as_independent_support(): void
    {
        $signals = array_map(
            static fn (int $index): CandidateSignal => new CandidateSignal(
                CandidateSignalKind::EmbeddedRecordingId,
                'recording-'.$index,
                'one-correlated-source',
                true,
                new CandidateIdentity(recordingId: 'recording-'.$index, releaseGroupId: self::RELEASE_GROUP_ID),
            ),
            range(1, 3),
        );
        $candidate = $this->albumCandidate(['Rare One', 'Rare Two', 'Rare Three'], $signals, title: 'Different Album');

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'Rare One', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Rare Two', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Rare Three', 'Example Artist', 210_000),
        ]));

        $this->assertNotSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(1, $decision->candidates[0]->featureVector['independent_recording_support']);
    }

    #[Test]
    public function independent_recording_support_uses_one_to_one_recording_family_matching(): void
    {
        $signals = [
            new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, 'r1', 'family-a', true, new CandidateIdentity(recordingId: 'r1')),
            new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, 'r2', 'family-a', true, new CandidateIdentity(recordingId: 'r2')),
            new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, 'r3', 'family-a', true, new CandidateIdentity(recordingId: 'r3')),
            new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, 'r1', 'family-b', true, new CandidateIdentity(recordingId: 'r1')),
            new CandidateSignal(CandidateSignalKind::EmbeddedRecordingId, 'r1', 'family-c', true, new CandidateIdentity(recordingId: 'r1')),
        ];
        $candidate = $this->albumCandidate(['Rare One', 'Rare Two', 'Rare Three'], $signals, title: 'Different Album');

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'Rare One', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Rare Two', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Rare Three', 'Example Artist', 210_000),
        ]));

        $this->assertSame(2, $decision->candidates[0]->featureVector['independent_recording_support']);
        $this->assertNotSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
    }

    #[Test]
    public function a_validated_embedded_release_outweighs_a_structurally_plausible_other_group(): void
    {
        $embedded = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
        );
        $otherGroup = $this->albumCandidate(
            ['First Light', 'Last Light'],
            $this->searchRecordingSignals(3, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            releaseId: '44444444-4444-4444-8444-444444444444',
            releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            identity: new CandidateIdentity(releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
        );

        $decision = $this->resolver([$embedded, $otherGroup])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
        $this->assertSame(self::RELEASE_ID, $decision->acceptedIdentity?->releaseId);
        $this->assertSame(self::RELEASE_ID, $decision->candidates[0]->identity->releaseId);
    }

    #[Test]
    public function an_embedded_release_group_prevents_accepting_a_higher_scoring_unrelated_edition(): void
    {
        $embeddedGroup = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(
                CandidateSignalKind::EmbeddedReleaseGroupId,
                self::RELEASE_GROUP_ID,
                'tag-file:1',
                true,
                new CandidateIdentity(releaseGroupId: self::RELEASE_GROUP_ID),
            )],
            identity: new CandidateIdentity(releaseGroupId: self::RELEASE_GROUP_ID),
        );
        $unrelatedIdentity = new CandidateIdentity(
            releaseId: '44444444-4444-4444-8444-444444444444',
            releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );
        $unrelatedEdition = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::DiscId, 'disc-1', 'cue:1', true, $unrelatedIdentity)],
            releaseId: (string) $unrelatedIdentity->releaseId,
            releaseGroupId: (string) $unrelatedIdentity->releaseGroupId,
            identity: $unrelatedIdentity,
        );

        $decision = $this->resolver([$unrelatedEdition, $embeddedGroup])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, releaseGroupId: self::RELEASE_GROUP_ID),
        ], complete: false));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertSame(self::RELEASE_GROUP_ID, $decision->candidates[0]->identity->releaseGroupId);
    }

    #[Test]
    public function an_embedded_release_track_prevents_accepting_an_unrelated_album_candidate(): void
    {
        $recordingId = '55555555-5555-4555-8555-555555555555';
        $recordingIdentity = new CandidateIdentity(
            recordingId: $recordingId,
            releaseId: self::RELEASE_ID,
            releaseGroupId: self::RELEASE_GROUP_ID,
        );
        $embeddedRecording = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(
                CandidateSignalKind::EmbeddedReleaseTrackId,
                '66666666-6666-4666-8666-666666666666',
                'tag-file:1',
                true,
                $recordingIdentity,
            )],
            identity: $recordingIdentity,
        );
        $unrelatedIdentity = new CandidateIdentity(
            releaseId: '44444444-4444-4444-8444-444444444444',
            releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );
        $unrelatedAlbum = $this->albumCandidate(
            ['First Light'],
            $this->searchRecordingSignals(3, (string) $unrelatedIdentity->releaseGroupId),
            releaseId: (string) $unrelatedIdentity->releaseId,
            releaseGroupId: (string) $unrelatedIdentity->releaseGroupId,
            identity: $unrelatedIdentity,
        );

        $decision = $this->resolver([$unrelatedAlbum, $embeddedRecording])->resolve($this->evidence([
            new TrackEvidence(
                1,
                'tag',
                1,
                '01.flac',
                'First Light',
                'Example Artist',
                180_000,
                musicBrainzReleaseTrackId: '66666666-6666-4666-8666-666666666666',
            ),
        ], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedRecording, $decision->status);
        $this->assertSame($recordingId, $decision->acceptedIdentity?->recordingId);
    }

    #[Test]
    public function edition_acceptance_uses_the_aligned_release_id_when_the_hypothesis_is_group_scoped(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            $this->recordingSignals(2),
            identity: new CandidateIdentity(releaseGroupId: self::RELEASE_GROUP_ID),
        );
        $evidence = new AudioEvidenceSet(
            evidenceId: 4,
            evidenceHash: str_repeat('d', 64),
            releaseTitle: 'Example Artist - Example Album',
            albumTitle: 'Example Album',
            albumArtist: 'Example Artist',
            releaseYear: 2020,
            trackEvidence: [
                new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
                new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
            ],
            trackEvidenceListComplete: true,
            country: 'US',
        );

        $decision = $this->resolver([$candidate])->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
        $this->assertSame(self::RELEASE_ID, $decision->acceptedIdentity?->releaseId);
        $this->assertSame(self::RELEASE_ID, $decision->candidates[0]->identity->releaseId);
    }

    #[Test]
    public function group_acceptance_uses_the_aligned_group_id_when_the_hypothesis_omits_it(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light', 'Final Light'],
            $this->searchRecordingSignals(3, self::RELEASE_GROUP_ID),
            identity: new CandidateIdentity(releaseId: self::RELEASE_ID),
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Final Light', 'Example Artist', 210_000),
        ], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(self::RELEASE_GROUP_ID, $decision->acceptedIdentity?->releaseGroupId);
    }

    #[Test]
    public function runner_up_scope_comparison_uses_the_aligned_release_group(): void
    {
        $signals = $this->searchRecordingSignals(3, self::RELEASE_GROUP_ID);
        $releaseHypothesis = $this->albumCandidate(
            ['First Light', 'Last Light', 'Final Light'],
            $signals,
            identity: new CandidateIdentity(releaseId: self::RELEASE_ID),
        );
        $groupHypothesis = $this->albumCandidate(
            ['First Light', 'Last Light', 'Final Light'],
            $signals,
            identity: new CandidateIdentity(releaseGroupId: self::RELEASE_GROUP_ID),
        );

        $decision = $this->resolver([$releaseHypothesis, $groupHypothesis])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
            new TrackEvidence(3, 'tag', 3, '03.flac', 'Final Light', 'Example Artist', 210_000),
        ], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertNull($decision->runnerUpMargin);
    }

    #[Test]
    public function two_recordings_plus_strong_album_title_artist_and_year_accept_a_release_group(): void
    {
        $candidate = $this->albumCandidate(['First Light', 'Last Light'], $this->recordingSignals(2));

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
    }

    #[Test]
    public function edition_specific_country_evidence_can_refine_an_accepted_album_to_an_edition(): void
    {
        $candidate = $this->albumCandidate(['First Light', 'Last Light'], $this->recordingSignals(2));
        $evidence = new AudioEvidenceSet(
            evidenceId: 4,
            evidenceHash: str_repeat('d', 64),
            releaseTitle: 'Example Artist - Example Album',
            albumTitle: 'Example Album',
            albumArtist: 'Example Artist',
            releaseYear: 2020,
            trackEvidence: [
                new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
                new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
            ],
            trackEvidenceListComplete: true,
            country: 'US',
        );

        $decision = $this->resolver([$candidate])->resolve($evidence);

        $this->assertSame(IdentificationStatus::AcceptedEdition, $decision->status);
        $this->assertSame(self::RELEASE_ID, $decision->acceptedIdentity?->releaseId);
    }

    #[Test]
    public function release_aliases_participate_in_album_title_agreement(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            $this->recordingSignals(2),
            title: 'Canonical Japanese Title',
            aliases: ['Example Album'],
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ]));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(1.0, $decision->candidates[0]->featureVector['release_title_agreement']);
    }

    #[Test]
    public function common_title_candidates_with_no_meaningful_margin_require_review(): void
    {
        $signals = [
            new CandidateSignal(CandidateSignalKind::ReleaseSearch, 'greatest hits', 'album-tags', false),
        ];
        $first = $this->albumCandidate(['The Hit', 'The Ballad'], $signals, title: 'Greatest Hits');
        $second = $this->albumCandidate(
            ['The Hit', 'The Ballad'],
            $signals,
            releaseId: '44444444-4444-4444-8444-444444444444',
            releaseGroupId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            title: 'Greatest Hits',
        );

        $decision = $this->resolver([$first, $second])->resolve(new AudioEvidenceSet(
            evidenceId: 2,
            evidenceHash: str_repeat('b', 64),
            releaseTitle: 'Example Artist - Greatest Hits',
            albumTitle: 'Greatest Hits',
            albumArtist: 'Example Artist',
            releaseYear: 2020,
            trackEvidence: [
                new TrackEvidence(1, 'tag', 1, '01.flac', 'The Hit', 'Example Artist', 180_000),
                new TrackEvidence(2, 'tag', 2, '02.flac', 'The Ballad', 'Example Artist', 210_000),
            ],
            trackEvidenceListComplete: true,
        ));

        $this->assertSame(IdentificationStatus::NeedsReview, $decision->status);
        $this->assertSame(0, $decision->runnerUpMargin);
    }

    #[Test]
    public function a_missing_bonus_track_does_not_block_ordered_group_acceptance_when_evidence_is_partial(): void
    {
        $signals = $this->recordingSignals(3);
        $candidate = $this->albumCandidate(['First Light', 'Last Light', 'Bonus Echo'], $signals);

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'archive', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'archive', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ], complete: false));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertSame(1, $decision->candidates[0]->featureVector['candidate_track_count'] - $decision->candidates[0]->featureVector['observed_track_count']);
        $this->assertSame(0, $decision->candidates[0]->scoreContributions['complete_track_count_agreement']);
    }

    #[Test]
    public function an_incompatible_fingerprint_is_a_hard_conflict(): void
    {
        $identity = new CandidateIdentity(recordingId: 'recording-1', releaseId: self::RELEASE_ID, releaseGroupId: self::RELEASE_GROUP_ID);
        $candidate = $this->albumCandidate(
            ['First Light'],
            [new CandidateSignal(CandidateSignalKind::Fingerprint, 'candidate-fingerprint', 'audio-file:1', true, $identity)],
            identity: $identity,
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'probe', 1, '01.flac', 'First Light', 'Example Artist', 180_000, fingerprint: 'observed-fingerprint'),
        ], complete: false));

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertContains('incompatible_fingerprint', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function a_strong_non_compilation_artist_conflict_blocks_an_exact_release(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
            artist: 'Entirely Different Artist',
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ]));

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertContains('strong_album_artist_conflict', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function a_featured_artist_credit_variant_is_not_a_hard_artist_conflict(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
            artist: 'Example Artist feat Guest Singer',
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ]));

        $this->assertNotSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertNotContains('strong_album_artist_conflict', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function a_member_name_is_not_treated_as_a_featured_variant_of_an_and_credit(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
            artist: 'Simon and Garfunkel',
        );
        $evidence = new AudioEvidenceSet(
            evidenceId: 5,
            evidenceHash: str_repeat('e', 64),
            releaseTitle: 'Garfunkel - Example Album',
            albumTitle: 'Example Album',
            albumArtist: 'Garfunkel',
            releaseYear: 2020,
            trackEvidence: [
                new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Garfunkel', 180_000, releaseId: self::RELEASE_ID),
                new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Garfunkel', 210_000, releaseId: self::RELEASE_ID),
            ],
            trackEvidenceListComplete: true,
        );

        $decision = $this->resolver([$candidate])->resolve($evidence);

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertContains('strong_album_artist_conflict', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function impossible_complete_duration_structure_is_a_hard_conflict(): void
    {
        $candidate = $this->albumCandidate(
            ['First Light', 'Last Light'],
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 400_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 500_000, releaseId: self::RELEASE_ID),
        ]));

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertContains('impossible_complete_duration_structure', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function a_complete_small_observation_conflicts_with_a_large_surplus_candidate_track_list(): void
    {
        $titles = ['First Light', 'Last Light'];
        foreach (range(3, 20) as $index) {
            $titles[] = 'Surplus Track '.$index;
        }
        $candidate = $this->albumCandidate(
            $titles,
            [new CandidateSignal(CandidateSignalKind::EmbeddedReleaseId, self::RELEASE_ID, 'tag-file:1', true)],
        );

        $decision = $this->resolver([$candidate])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000, releaseId: self::RELEASE_ID),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000, releaseId: self::RELEASE_ID),
        ]));

        $this->assertSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertContains('impossible_complete_track_structure', $decision->candidates[0]->contradictions);
        $this->assertLessThan(0, $decision->candidates[0]->scoreContributions['unmatched_candidate_penalty']);
    }

    #[Test]
    public function compilation_artist_credit_does_not_create_a_false_artist_conflict(): void
    {
        $candidate = $this->albumCandidate(
            ['Solo Song', 'Duo Song'],
            $this->recordingSignals(3),
            artist: 'Various Artists',
        );
        $evidence = new AudioEvidenceSet(
            evidenceId: 3,
            evidenceHash: str_repeat('c', 64),
            releaseTitle: 'Compilation',
            albumTitle: 'Example Album',
            albumArtist: 'Various Artists',
            releaseYear: 2020,
            trackEvidence: [
                new TrackEvidence(1, 'tag', 1, '01.flac', 'Solo Song', 'Singer One', 180_000),
                new TrackEvidence(2, 'tag', 2, '02.flac', 'Duo Song', 'Singer Two feat Singer Three', 210_000),
            ],
            trackEvidenceListComplete: true,
        );

        $decision = $this->resolver([$candidate])->resolve($evidence);

        $this->assertNotSame(IdentificationStatus::Conflicted, $decision->status);
        $this->assertNotContains('strong_album_artist_conflict', $decision->candidates[0]->contradictions);
    }

    #[Test]
    public function original_and_remaster_editions_with_equal_structure_collapse_to_the_release_group(): void
    {
        $signals = $this->recordingSignals(3);
        $original = $this->albumCandidate(['First Light', 'Last Light'], $signals, date: '1990-01-01');
        $remaster = $this->albumCandidate(
            ['First Light', 'Last Light'],
            $signals,
            releaseId: '44444444-4444-4444-8444-444444444444',
            date: '2020-01-01',
        );

        $decision = $this->resolver([$original, $remaster])->resolve($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'First Light', 'Example Artist', 180_000),
            new TrackEvidence(2, 'tag', 2, '02.flac', 'Last Light', 'Example Artist', 210_000),
        ], year: null));

        $this->assertSame(IdentificationStatus::AcceptedReleaseGroup, $decision->status);
        $this->assertNull($decision->acceptedIdentity?->releaseId);
        $this->assertNull($decision->runnerUpMargin);
    }

    #[Test]
    public function provider_failure_is_retryable_but_a_successful_empty_pool_is_unresolved(): void
    {
        $evidence = $this->evidence([]);

        $retryable = (new MusicIdentityResolver(new ThrowingCandidateGenerator))->resolve($evidence);
        $unresolved = $this->resolver([])->resolve($evidence);

        $this->assertSame(IdentificationStatus::RetryableError, $retryable->status);
        $this->assertSame('mirror unavailable', $retryable->operationalError);
        $this->assertSame(IdentificationStatus::Unresolved, $unresolved->status);
        $this->assertNull($unresolved->operationalError);
    }

    /** @param list<TrackEvidence> $trackEvidence */
    private function evidence(array $trackEvidence, ?bool $complete = true, ?int $year = 2020): AudioEvidenceSet
    {
        return new AudioEvidenceSet(
            evidenceId: 1,
            evidenceHash: str_repeat('a', 64),
            releaseTitle: 'Example Artist - Example Album',
            albumTitle: 'Example Album',
            albumArtist: 'Example Artist',
            releaseYear: $year,
            trackEvidence: $trackEvidence,
            trackEvidenceListComplete: $complete,
            albumProvenanceFamily: 'album-tags',
        );
    }

    /**
     * @param  list<string>  $titles
     * @param  list<CandidateSignal>  $signals
     * @param  list<string>  $aliases
     */
    private function albumCandidate(
        array $titles,
        array $signals,
        string $releaseId = self::RELEASE_ID,
        string $releaseGroupId = self::RELEASE_GROUP_ID,
        string $title = 'Example Album',
        string $artist = 'Example Artist',
        string $date = '2020-01-01',
        ?CandidateIdentity $identity = null,
        array $aliases = [],
    ): CandidateHypothesis {
        $releaseTracks = [];
        foreach ($titles as $index => $trackTitle) {
            $recordingId = sprintf('22222222-2222-4222-8222-%012d', $index + 1);
            $releaseTracks[] = [
                'musicBrainzReleaseTrackId' => sprintf('33333333-3333-4333-8333-%012d', $index + 1),
                'title' => $trackTitle,
                'position' => $index + 1,
                'number' => (string) ($index + 1),
                'lengthMs' => $index === 0 ? 180_000 : 210_000,
                'artistCredit' => $artist,
                'recording' => [
                    'recordingId' => $recordingId,
                    'title' => $trackTitle,
                    'artistCredit' => $artist,
                    'lengthMs' => $index === 0 ? 180_000 : 210_000,
                    'video' => false,
                    'isrcs' => [],
                    'releaseIds' => [$releaseId],
                    'releaseGroupIds' => [$releaseGroupId],
                    'providerScore' => null,
                    'sources' => ['fixture'],
                ],
            ];
        }

        return new CandidateHypothesis(
            $identity ?? new CandidateIdentity(releaseId: $releaseId, releaseGroupId: $releaseGroupId),
            new CandidateMetadata([], [[
                'releaseId' => $releaseId,
                'title' => $title,
                'artistCredit' => $artist,
                'releaseGroupId' => $releaseGroupId,
                'status' => 'Official',
                'date' => $date,
                'country' => 'US',
                'barcode' => null,
                'labels' => [],
                'aliases' => $aliases,
                'media' => [[
                    'position' => 1,
                    'title' => null,
                    'format' => 'CD',
                    'releaseTrackCount' => count($releaseTracks),
                    'discIds' => [],
                    'releaseTracks' => $releaseTracks,
                ]],
            ]], [[
                'releaseGroupId' => $releaseGroupId,
                'title' => $title,
                'artistCredit' => $artist,
                'primaryType' => 'Album',
                'secondaryTypes' => [],
                'firstReleaseDate' => $date,
                'aliases' => $aliases,
            ]]),
            $signals,
        );
    }

    /** @return list<CandidateSignal> */
    private function recordingSignals(int $count): array
    {
        $signals = [];
        foreach (range(1, $count) as $index) {
            $recordingId = sprintf('55555555-5555-4555-8555-%012d', $index);
            $signals[] = new CandidateSignal(
                CandidateSignalKind::EmbeddedRecordingId,
                $recordingId,
                'tag-file:'.$index,
                true,
                new CandidateIdentity(recordingId: $recordingId, releaseGroupId: self::RELEASE_GROUP_ID),
            );
        }

        return $signals;
    }

    /** @return list<CandidateSignal> */
    private function searchRecordingSignals(int $count, string $releaseGroupId): array
    {
        $signals = [];
        foreach (range(1, $count) as $index) {
            $recordingId = sprintf('77777777-7777-4777-8777-%012d', $index);
            $signals[] = new CandidateSignal(
                CandidateSignalKind::TrackEvidenceSearch,
                'search-result-'.$index,
                'search-file:'.$index,
                false,
                new CandidateIdentity(recordingId: $recordingId, releaseGroupId: $releaseGroupId),
            );
        }

        return $signals;
    }

    /** @param list<CandidateHypothesis> $candidates */
    private function resolver(array $candidates): MusicIdentityResolver
    {
        return new MusicIdentityResolver(new FixedCandidateGenerator(new CandidatePool($candidates)));
    }
}

final readonly class FixedCandidateGenerator implements CandidateGenerator
{
    public function __construct(private CandidatePool $pool) {}

    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        unset($evidence);

        return $this->pool;
    }
}

final readonly class ThrowingCandidateGenerator implements CandidateGenerator
{
    public function generate(AudioEvidenceSet $evidence): CandidatePool
    {
        unset($evidence);

        throw new MusicBrainzGatewayException('mirror unavailable');
    }
}
