<?php

declare(strict_types=1);

namespace Tests\Unit\MusicIdentity;

use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\TrackEvidence;
use App\Services\MusicIdentity\Matching\ReleaseTrackSequenceAligner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleaseTrackSequenceAlignerTest extends TestCase
{
    #[Test]
    public function it_numeric_sorts_lexicographic_filenames_before_alignment(): void
    {
        $evidence = $this->evidence([
            new TrackEvidence(1, 'archive', 1, '1 - One.flac', 'One', durationMs: 180_000),
            new TrackEvidence(2, 'archive', 2, '10 - Ten.flac', 'Ten', durationMs: 200_000),
            new TrackEvidence(3, 'archive', 3, '2 - Two.flac', 'Two', durationMs: 190_000),
        ]);

        $alignment = (new ReleaseTrackSequenceAligner)->align($evidence, $this->release([
            ['One', 180_000],
            ['Two', 190_000],
            ['Ten', 200_000],
        ]));

        $this->assertSame(1.0, $alignment->observedCoverage);
        $this->assertSame(1.0, $alignment->titleAgreement);
        $this->assertSame(1.0, $alignment->orderAgreement);
        $this->assertSame(1.0, $alignment->contiguousCoverage);
    }

    #[Test]
    public function it_aligns_partial_discs_and_skips_missing_bonus_and_pregap_tracks(): void
    {
        $evidence = $this->evidence([
            new TrackEvidence(1, 'archive', 1, 'CD2/01 - Part Two.flac', 'Part Two', durationMs: 180_000, discNumber: 2, releaseTrackNumber: 1),
            new TrackEvidence(2, 'archive', 2, 'CD2/02 - Finale.flac', 'Finale', durationMs: 210_000, discNumber: 2, releaseTrackNumber: 2),
        ], complete: false);
        $release = $this->release(
            [['Pregap', 30_000], ['Part One', 170_000], ['Bonus', 60_000]],
            [['Part Two', 180_000], ['Finale', 210_000], ['Hidden', 45_000]],
        );

        $alignment = (new ReleaseTrackSequenceAligner)->align($evidence, $release);

        $this->assertSame(1.0, $alignment->observedCoverage);
        $this->assertSame(2, count($alignment->matches));
        $this->assertSame(4, $alignment->unmatchedCandidate);
        $this->assertSame(1.0, $alignment->perDiscPositionAgreement);
    }

    #[Test]
    public function recording_duration_is_used_when_release_track_duration_is_missing(): void
    {
        $release = $this->release([['One', 180_000]]);
        $release['media'][0]['releaseTracks'][0]['lengthMs'] = null;
        $alignment = (new ReleaseTrackSequenceAligner)->align($this->evidence([
            new TrackEvidence(1, 'tag', 1, '01.flac', 'One', durationMs: 180_000),
        ]), $release);

        $this->assertSame(1.0, $alignment->durationAgreement);
    }

    #[Test]
    public function ordered_matches_separated_by_a_missing_candidate_track_remain_ordered_but_not_contiguous(): void
    {
        $alignment = (new ReleaseTrackSequenceAligner)->align($this->evidence([
            new TrackEvidence(1, 'archive', 1, '01.flac', 'First', durationMs: 180_000),
            new TrackEvidence(2, 'archive', 2, '02.flac', 'Last', durationMs: 210_000),
        ], complete: false), $this->release([
            ['First', 180_000],
            ['Hidden', 30_000],
            ['Last', 210_000],
        ]));

        $this->assertSame(1.0, $alignment->orderAgreement);
        $this->assertSame(0.5, $alignment->contiguousCoverage);
    }

    /** @param list<TrackEvidence> $trackEvidenceItems */
    private function evidence(array $trackEvidenceItems, ?bool $complete = true): AudioEvidenceSet
    {
        return new AudioEvidenceSet(
            evidenceId: 1,
            evidenceHash: str_repeat('a', 64),
            releaseTitle: 'Album',
            albumTitle: 'Album',
            albumArtist: 'Artist',
            releaseYear: 2020,
            trackEvidence: $trackEvidenceItems,
            trackEvidenceListComplete: $complete,
        );
    }

    /**
     * @param  list<array{string, int}>  $firstDisc
     * @param  list<array{string, int}>  $secondDisc
     * @return array{media: list<array{position: int, releaseTracks: list<array{title: string, artistCredit: string, position: int, lengthMs: int|null, recording: array{lengthMs: int}}>}>, releaseId: string, title: string, artistCredit: string, releaseGroupId: string, status: string, date: string, country: string, barcode: null, labels: array{}}
     */
    private function release(array $firstDisc, array $secondDisc = []): array
    {
        $media = [['position' => 1, 'releaseTracks' => $this->releaseTracks($firstDisc)]];
        if ($secondDisc !== []) {
            $media[] = ['position' => 2, 'releaseTracks' => $this->releaseTracks($secondDisc)];
        }

        return [
            'releaseId' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Album',
            'artistCredit' => 'Artist',
            'releaseGroupId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'status' => 'Official',
            'date' => '2020-01-01',
            'country' => 'US',
            'barcode' => null,
            'labels' => [],
            'media' => $media,
        ];
    }

    /**
     * @param  list<array{string, int}>  $releaseTrackFixtures
     * @return list<array{title: string, artistCredit: string, position: int, lengthMs: int|null, recording: array{lengthMs: int}}>
     */
    private function releaseTracks(array $releaseTrackFixtures): array
    {
        return array_map(
            static fn (array $releaseTrackFixture, int $index): array => [
                'title' => $releaseTrackFixture[0],
                'artistCredit' => 'Artist',
                'position' => $index + 1,
                'lengthMs' => $releaseTrackFixture[1],
                'recording' => ['lengthMs' => $releaseTrackFixture[1]],
            ],
            $releaseTrackFixtures,
            array_keys($releaseTrackFixtures),
        );
    }
}
