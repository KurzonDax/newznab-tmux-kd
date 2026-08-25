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
    ) {}

    public static function fetched(string $path, string $extension, ?MediaInfoContainer $mediaInfo): self
    {
        return new self($path, strtolower($extension), $mediaInfo, false, false, '');
    }

    public static function declined(string $reason): self
    {
        return new self(null, '', null, true, false, $reason);
    }

    public static function failed(string $reason, bool $archivePassworded = false): self
    {
        return new self(null, '', null, false, $archivePassworded, $reason);
    }

    public function succeeded(): bool
    {
        return $this->path !== null;
    }
}
