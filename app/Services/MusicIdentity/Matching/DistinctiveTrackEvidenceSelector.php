<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Matching;

use App\Services\MusicIdentity\DTO\TrackEvidence;

final readonly class DistinctiveTrackEvidenceSelector
{
    private const array WEAK_TITLES = [
        'bonus',
        'hidden track',
        'interlude',
        'intro',
        'outro',
        'track',
        'unknown',
        'untitled',
    ];

    public function __construct(private CandidateTextNormalizer $normalizer) {}

    /**
     * @param  list<TrackEvidence>  $trackEvidence
     * @return list<TrackEvidence>
     */
    public function select(array $trackEvidence, int $limit): array
    {
        $tokenFrequencies = [];
        foreach ($trackEvidence as $evidenceItem) {
            $title = $this->normalizer->normalize($evidenceItem->title ?? $evidenceItem->rawFilename);
            if ($title === null) {
                continue;
            }
            foreach (array_unique($this->meaningfulTokens($title)) as $token) {
                $tokenFrequencies[$token] = ($tokenFrequencies[$token] ?? 0) + 1;
            }
        }

        $ranked = [];
        foreach ($trackEvidence as $evidenceItem) {
            $title = $this->normalizer->normalize($evidenceItem->title ?? $evidenceItem->rawFilename);
            if ($title === null || in_array($title, self::WEAK_TITLES, true)) {
                continue;
            }

            $tokens = $this->meaningfulTokens($title);
            if ($tokens === []) {
                continue;
            }

            $uniqueTokens = array_values(array_unique($tokens));
            $averageRarity = array_sum(array_map(
                static fn (string $token): float => 1 / $tokenFrequencies[$token],
                $uniqueTokens,
            )) / count($uniqueTokens);

            $ranked[] = [
                'evidence' => $evidenceItem,
                'score' => (int) round($averageRarity * 1_000)
                    + ($this->normalizer->normalize($evidenceItem->artist) === null ? 0 : 250)
                    + count($uniqueTokens) * 10
                    + mb_strlen($title),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: $left['evidence']->sourceOrdinal <=> $right['evidence']->sourceOrdinal;
        });

        return array_values(array_map(
            static fn (array $item): TrackEvidence => $item['evidence'],
            array_slice($ranked, 0, max(0, $limit)),
        ));
    }

    /** @return list<string> */
    private function meaningfulTokens(string $title): array
    {
        return array_values(array_filter(
            explode(' ', $title),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));
    }
}
