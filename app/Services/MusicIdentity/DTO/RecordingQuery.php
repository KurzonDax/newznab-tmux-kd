<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

/**
 * @phpstan-type NormalizedRecordingQuery array{
 *     artist: string|null,
 *     discId: string|null,
 *     durationMs: int|null,
 *     durationToleranceMs: int,
 *     isrc: string|null,
 *     limit: int|null,
 *     offset: int,
 *     recordingId: string|null,
 *     releaseTitle: string|null,
 *     title: string|null
 * }
 */
final readonly class RecordingQuery
{
    public function __construct(
        public ?string $recordingId = null,
        public ?string $isrc = null,
        public ?string $discId = null,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $releaseTitle = null,
        public ?int $durationMs = null,
        public int $durationToleranceMs = 5_000,
        public ?int $limit = null,
        public int $offset = 0,
    ) {}

    /** @return NormalizedRecordingQuery */
    public function normalized(): array
    {
        return [
            'artist' => $this->text($this->artist),
            'discId' => $this->text($this->discId),
            'durationMs' => $this->durationMs === null ? null : max(0, $this->durationMs),
            'durationToleranceMs' => max(0, $this->durationToleranceMs),
            'isrc' => $this->identifier($this->isrc, uppercase: true),
            'limit' => $this->limit === null ? null : min(100, max(1, $this->limit)),
            'offset' => max(0, $this->offset),
            'recordingId' => $this->identifier($this->recordingId),
            'releaseTitle' => $this->text($this->releaseTitle),
            'title' => $this->text($this->title),
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
