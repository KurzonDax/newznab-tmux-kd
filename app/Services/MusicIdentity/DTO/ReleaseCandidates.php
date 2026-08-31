<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * @phpstan-type ReleaseCandidate array{
 *     releaseId: string,
 *     releaseGroupId: string|null,
 *     title: string,
 *     artistCredit: string|null,
 *     providerScore: int|null,
 *     sources: list<string>
 * }
 */
final readonly class ReleaseCandidates
{
    /** @param list<ReleaseCandidate> $releases
     * @param  list<string>  $responseCacheKeys
     */
    public function __construct(
        public array $releases,
        public int $providerTotal = 0,
        public array $responseCacheKeys = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }
}
