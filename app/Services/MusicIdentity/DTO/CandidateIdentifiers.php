<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * @phpstan-type NormalizedCandidateIdentifiers array{
 *     discId: string|null,
 *     isrc: string|null,
 *     recordingId: string|null,
 *     releaseGroupId: string|null,
 *     releaseId: string|null
 * }
 */
final readonly class CandidateIdentifiers
{
    public function __construct(
        public ?string $recordingId = null,
        public ?string $releaseId = null,
        public ?string $releaseGroupId = null,
        public ?string $isrc = null,
        public ?string $discId = null,
    ) {}

    /** @return NormalizedCandidateIdentifiers */
    public function normalized(): array
    {
        return [
            'discId' => $this->text($this->discId),
            'isrc' => $this->identifier($this->isrc, uppercase: true),
            'recordingId' => $this->identifier($this->recordingId),
            'releaseGroupId' => $this->identifier($this->releaseGroupId),
            'releaseId' => $this->identifier($this->releaseId),
        ];
    }

    private function identifier(?string $value, bool $uppercase = false): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : ($uppercase ? strtoupper($value) : strtolower($value));
    }

    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
