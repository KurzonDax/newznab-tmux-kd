<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Matching;

use App\Services\MusicIdentity\DTO\AlignmentResult;
use App\Services\MusicIdentity\DTO\AudioEvidenceSet;
use App\Services\MusicIdentity\DTO\TrackEvidence;

final readonly class ReleaseTrackSequenceAligner
{
    public function __construct(private CandidateTextNormalizer $normalizer = new CandidateTextNormalizer) {}

    /**
     * @param  array{media: list<array{position: int|null, releaseTracks: list<array{title: string, artistCredit: string|null, position: int|null, lengthMs: int|null, recording: array{lengthMs: int|null}|null}>}>}  $release
     */
    public function align(AudioEvidenceSet $evidence, array $release): AlignmentResult
    {
        $observedTracks = $this->orderedEvidence($evidence->trackEvidence);
        $releaseTracks = $this->orderedReleaseTracks($release['media']);
        $observedCount = count($observedTracks);
        $candidateCount = count($releaseTracks);

        if ($observedCount === 0 || $candidateCount === 0) {
            return AlignmentResult::empty($observedCount, $candidateCount);
        }

        $scores = array_fill(0, $observedCount + 1, array_fill(0, $candidateCount + 1, 0.0));
        $moves = array_fill(0, $observedCount + 1, array_fill(0, $candidateCount + 1, ''));

        for ($observedIndex = 1; $observedIndex <= $observedCount; $observedIndex++) {
            $scores[$observedIndex][0] = $scores[$observedIndex - 1][0] - 0.9;
            $moves[$observedIndex][0] = 'observed-gap';
        }
        for ($candidateIndex = 1; $candidateIndex <= $candidateCount; $candidateIndex++) {
            $moves[0][$candidateIndex] = 'candidate-gap';
        }

        for ($observedIndex = 1; $observedIndex <= $observedCount; $observedIndex++) {
            for ($candidateIndex = 1; $candidateIndex <= $candidateCount; $candidateIndex++) {
                $agreements = $this->agreements($observedTracks[$observedIndex - 1], $releaseTracks[$candidateIndex - 1]);
                $pairScore = ($agreements['title'] * 2.2) + ($agreements['duration'] * 0.8) + ($agreements['artistCredit'] * 0.2) - 1.0;
                $options = [
                    'match' => $scores[$observedIndex - 1][$candidateIndex - 1] + $pairScore,
                    'candidate-gap' => $scores[$observedIndex][$candidateIndex - 1] - 0.35,
                    'observed-gap' => $scores[$observedIndex - 1][$candidateIndex] - 0.9,
                ];
                $bestMove = array_keys($options, max($options), true)[0];
                $scores[$observedIndex][$candidateIndex] = $options[$bestMove];
                $moves[$observedIndex][$candidateIndex] = $bestMove;
            }
        }

        $candidateIndex = 0;
        $bestTerminalScore = -INF;
        for ($index = 0; $index <= $candidateCount; $index++) {
            if ($scores[$observedCount][$index] > $bestTerminalScore) {
                $bestTerminalScore = $scores[$observedCount][$index];
                $candidateIndex = $index;
            }
        }

        $matches = [];
        $observedIndex = $observedCount;
        while ($observedIndex > 0 && $candidateIndex > 0) {
            $move = $moves[$observedIndex][$candidateIndex];
            if ($move === 'match') {
                $agreements = $this->agreements($observedTracks[$observedIndex - 1], $releaseTracks[$candidateIndex - 1]);
                if ($agreements['title'] >= 0.45 || ($agreements['title'] >= 0.25 && $agreements['duration'] >= 0.8)) {
                    $matches[] = [
                        'evidenceIndex' => $observedIndex - 1,
                        'releaseTrackIndex' => $candidateIndex - 1,
                        'titleAgreement' => $agreements['title'],
                        'artistCreditAgreement' => $agreements['artistCredit'],
                        'artistCreditCompared' => $agreements['artistCreditCompared'],
                        'durationAgreement' => $agreements['duration'],
                        'durationCompared' => $agreements['durationCompared'],
                        'perDiscPositionAgreement' => $agreements['perDiscPosition'],
                    ];
                }
                $observedIndex--;
                $candidateIndex--;
            } elseif ($move === 'candidate-gap') {
                $candidateIndex--;
            } else {
                $observedIndex--;
            }
        }

        $matches = array_reverse($matches);
        $matchCount = count($matches);
        $titleAgreement = $matchCount === 0 ? 0.0 : array_sum(array_column($matches, 'titleAgreement')) / $matchCount;
        $durationMatches = array_column(array_values(array_filter(
            $matches,
            static fn (array $match): bool => $match['durationCompared'],
        )), 'durationAgreement');
        $durationAgreement = $durationMatches === [] ? 0.0 : array_sum($durationMatches) / count($durationMatches);
        $artistCreditMatches = array_column(array_values(array_filter(
            $matches,
            static fn (array $match): bool => $match['artistCreditCompared'],
        )), 'artistCreditAgreement');
        $artistCreditAgreement = $artistCreditMatches === [] ? 0.0 : array_sum($artistCreditMatches) / count($artistCreditMatches);
        [$orderAgreement, $contiguousCoverage] = $this->sequenceAgreements($matches, $observedCount);
        $perDiscPositionAgreement = $matchCount === 0 ? 0.0 : array_sum(array_column($matches, 'perDiscPositionAgreement')) / $matchCount;

        return new AlignmentResult(
            matches: $matches,
            observedCount: $observedCount,
            candidateCount: $candidateCount,
            titleAgreement: round($titleAgreement, 4),
            durationAgreement: round($durationAgreement, 4),
            durationComparisonCount: count($durationMatches),
            artistCreditAgreement: round($artistCreditAgreement, 4),
            artistCreditComparisonCount: count($artistCreditMatches),
            orderAgreement: round($orderAgreement, 4),
            contiguousCoverage: round($contiguousCoverage, 4),
            perDiscPositionAgreement: round($perDiscPositionAgreement, 4),
            observedCoverage: round($matchCount / $observedCount, 4),
            candidateCoverage: round($matchCount / $candidateCount, 4),
            unmatchedObserved: $observedCount - $matchCount,
            unmatchedCandidate: $candidateCount - $matchCount,
        );
    }

    /** @param list<TrackEvidence> $trackEvidenceItems
     * @return list<TrackEvidence>
     */
    private function orderedEvidence(array $trackEvidenceItems): array
    {
        usort($trackEvidenceItems, fn (TrackEvidence $left, TrackEvidence $right): int => $this->evidencePosition($left) <=> $this->evidencePosition($right));

        return $trackEvidenceItems;
    }

    /** @return array{int, int, int} */
    private function evidencePosition(TrackEvidence $trackEvidence): array
    {
        if ($trackEvidence->discNumber !== null || $trackEvidence->releaseTrackNumber !== null) {
            return [$trackEvidence->discNumber ?? 1, $trackEvidence->releaseTrackNumber ?? $trackEvidence->sourceOrdinal, $trackEvidence->sourceOrdinal];
        }
        if (preg_match('~(?:^|[\\\\/ _.-])(?:cd|disc)?\s*(\d{1,2})[-_. \\\\/](\d{1,3})(?:\D|$)~iu', $trackEvidence->rawFilename, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2], $trackEvidence->sourceOrdinal];
        }
        if (preg_match('~(?:^|[\\\\/ _.-])(\d{1,3})(?:\D|$)~u', $trackEvidence->rawFilename, $matches) === 1) {
            return [1, (int) $matches[1], $trackEvidence->sourceOrdinal];
        }

        return [1, $trackEvidence->sourceOrdinal, $trackEvidence->sourceOrdinal];
    }

    /**
     * @param  list<array{position: int|null, releaseTracks: list<array{title: string, artistCredit: string|null, position: int|null, lengthMs: int|null, recording: array{lengthMs: int|null}|null}>}>  $media
     * @return list<array{title: string, artistCredit: string|null, position: int|null, lengthMs: int|null, recording: array{lengthMs: int|null}|null, mediumPosition: int|null}>
     */
    private function orderedReleaseTracks(array $media): array
    {
        usort($media, static fn (array $left, array $right): int => ($left['position'] ?? PHP_INT_MAX) <=> ($right['position'] ?? PHP_INT_MAX));
        $releaseTracks = [];
        foreach ($media as $medium) {
            $mediumTracks = $medium['releaseTracks'];
            usort($mediumTracks, static fn (array $left, array $right): int => ($left['position'] ?? PHP_INT_MAX) <=> ($right['position'] ?? PHP_INT_MAX));
            foreach ($mediumTracks as $releaseTrack) {
                $releaseTracks[] = [...$releaseTrack, 'mediumPosition' => $medium['position']];
            }
        }

        return $releaseTracks;
    }

    /** @param array{title: string, artistCredit: string|null, position: int|null, lengthMs: int|null, recording: array{lengthMs: int|null}|null, mediumPosition: int|null} $releaseTrack
     * @return array{title: float, artistCredit: float, artistCreditCompared: bool, duration: float, durationCompared: bool, perDiscPosition: float}
     */
    private function agreements(TrackEvidence $evidence, array $releaseTrack): array
    {
        $observedTitle = $this->normalizer->normalize($evidence->title ?? $evidence->rawFilename);
        $candidateTitle = $this->normalizer->normalize($releaseTrack['title']);
        $candidateDuration = $releaseTrack['lengthMs'] ?? $releaseTrack['recording']['lengthMs'] ?? null;
        $observedArtist = $this->normalizer->normalize($evidence->artist);
        $candidateArtist = $this->normalizer->normalize($releaseTrack['artistCredit']);
        $evidencePosition = $this->evidencePosition($evidence);

        return [
            'title' => $this->textAgreement($observedTitle, $candidateTitle),
            'artistCredit' => $this->textAgreement($observedArtist, $candidateArtist),
            'artistCreditCompared' => $observedArtist !== null && $candidateArtist !== null,
            'duration' => $this->durationAgreement($evidence->durationMs, $candidateDuration),
            'durationCompared' => $evidence->durationMs !== null && $evidence->durationMs > 0 && $candidateDuration !== null && $candidateDuration > 0,
            'perDiscPosition' => $evidencePosition[0] === ($releaseTrack['mediumPosition'] ?? 1)
                && $evidencePosition[1] === ($releaseTrack['position'] ?? $evidencePosition[1]) ? 1.0 : 0.0,
        ];
    }

    /**
     * @param  list<array{evidenceIndex: int, releaseTrackIndex: int}>  $matches
     * @return array{float, float}
     */
    private function sequenceAgreements(array $matches, int $observedCount): array
    {
        if (count($matches) < 2) {
            return [$matches === [] ? 0.0 : 1.0, $matches === [] ? 0.0 : 1 / $observedCount];
        }

        $orderedTransitions = 0;
        $longestRun = 1;
        $currentRun = 1;
        for ($index = 1; $index < count($matches); $index++) {
            $isOrdered = $matches[$index]['evidenceIndex'] > $matches[$index - 1]['evidenceIndex']
                && $matches[$index]['releaseTrackIndex'] > $matches[$index - 1]['releaseTrackIndex'];
            if ($isOrdered) {
                $orderedTransitions++;
            }
            $isContiguous = $matches[$index]['evidenceIndex'] === $matches[$index - 1]['evidenceIndex'] + 1
                && $matches[$index]['releaseTrackIndex'] === $matches[$index - 1]['releaseTrackIndex'] + 1;
            if ($isContiguous) {
                $currentRun++;
                $longestRun = max($longestRun, $currentRun);
            } else {
                $currentRun = 1;
            }
        }

        return [
            $orderedTransitions / (count($matches) - 1),
            $longestRun / $observedCount,
        ];
    }

    private function textAgreement(?string $left, ?string $right): float
    {
        if ($left === null || $right === null) {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }
        $leftTokens = array_values(array_unique(explode(' ', $left)));
        $rightTokens = array_values(array_unique(explode(' ', $right)));
        $union = array_unique([...$leftTokens, ...$rightTokens]);

        return $union === [] ? 0.0 : count(array_intersect($leftTokens, $rightTokens)) / count($union);
    }

    private function durationAgreement(?int $observedMs, ?int $candidateMs): float
    {
        if ($observedMs === null || $candidateMs === null || $observedMs <= 0 || $candidateMs <= 0) {
            return 0.0;
        }
        $difference = abs($observedMs - $candidateMs);

        return match (true) {
            $difference <= 2_000 => 1.0,
            $difference <= 5_000 => 0.9,
            $difference <= 10_000 => 0.7,
            $difference / max($observedMs, $candidateMs) <= 0.1 => 0.4,
            default => 0.0,
        };
    }
}
