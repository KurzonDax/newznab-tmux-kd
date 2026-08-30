<?php

declare(strict_types=1);

namespace App\Services\AudioProcessing\DTO;

use Mhor\MediaInfo\Container\MediaInfoContainer;

/**
 * The outcome of fetching audio for one release.
 *
 * Exactly one of three shapes: fetched (a readable file plus the MediaInfo read
 * off its head), declined (the probe found video, or no audio at all), or
 * failed (nothing usable, but the release stays on the audio path).
 */
final readonly class AudioFetchResult
{
    private function __construct(
        public ?string $path,
        public string $extension,
        public ?MediaInfoContainer $mediaInfo,
        public bool $declined,
        public bool $archivePassworded,
        public string $reason,
        public int $crcFailures,
        /** @var list<array<string, mixed>> */
        public array $archiveMembers,
        public ?string $sampledFilename,
        public ?bool $archiveManifestComplete,
        public ?bool $sourceFileComplete,
        public ?bool $sourceStartsAtZero,
        public ?bool $wholeDurationReliable,
        public ?bool $onlyOneTrackProbed,
        public ?float $decodedDurationSeconds,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $archiveMembers
     */
    public static function fetched(
        string $path,
        string $extension,
        ?MediaInfoContainer $mediaInfo,
        ?string $sampledFilename = null,
        array $archiveMembers = [],
        ?bool $archiveManifestComplete = null,
        ?bool $sourceFileComplete = null,
        ?bool $sourceStartsAtZero = null,
        ?bool $wholeDurationReliable = null,
        ?bool $onlyOneTrackProbed = null,
        ?float $decodedDurationSeconds = null,
    ): self {
        return new self(
            $path,
            strtolower($extension),
            $mediaInfo,
            false,
            false,
            '',
            0,
            $archiveMembers,
            $sampledFilename,
            $archiveManifestComplete,
            $sourceFileComplete,
            $sourceStartsAtZero,
            $wholeDurationReliable,
            $onlyOneTrackProbed,
            $decodedDurationSeconds,
        );
    }

    public static function declined(string $reason): self
    {
        return new self(null, '', null, true, false, $reason, 0, [], null, null, null, null, null, null, null);
    }

    public static function failed(string $reason, bool $archivePassworded = false): self
    {
        return new self(null, '', null, false, $archivePassworded, $reason, 0, [], null, null, null, null, null, null, null);
    }

    public function withCrcFailures(int $crcFailures): self
    {
        return new self(
            $this->path,
            $this->extension,
            $this->mediaInfo,
            $this->declined,
            $this->archivePassworded,
            $this->reason,
            $crcFailures,
            $this->archiveMembers,
            $this->sampledFilename,
            $this->archiveManifestComplete,
            $this->sourceFileComplete,
            $this->sourceStartsAtZero,
            $this->wholeDurationReliable,
            $this->onlyOneTrackProbed,
            $this->decodedDurationSeconds,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $archiveMembers
     */
    public function withEvidence(
        array $archiveMembers,
        ?bool $archiveManifestComplete,
        ?bool $onlyOneTrackProbed,
        ?string $sampledFilename,
    ): self {
        return new self(
            $this->path,
            $this->extension,
            $this->mediaInfo,
            $this->declined,
            $this->archivePassworded,
            $this->reason,
            $this->crcFailures,
            $archiveMembers,
            $this->sampledFilename ?? $sampledFilename,
            $archiveManifestComplete,
            $this->sourceFileComplete,
            $this->sourceStartsAtZero,
            $this->wholeDurationReliable,
            $onlyOneTrackProbed,
            $this->decodedDurationSeconds,
        );
    }

    public function succeeded(): bool
    {
        return $this->path !== null;
    }
}
