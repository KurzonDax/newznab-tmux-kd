<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The preview an Audio release's details page plays in its Overview (docs/proposals/audio-redesign/SPEC.md
 * 5C.1): today's player route, its length, the previewed track's title and the spectrogram under the
 * player.
 */
final readonly class AudioPreview
{
    /**
     * @param  int|null  $seconds  the preview's stored length
     * @param  string  $trackTitle  the previewed track's title tag; '' when none is stored
     * @param  string|null  $spectrogramUrl  null without a stored spectrogram file
     */
    public function __construct(
        public string $url,
        public ?int $seconds,
        public string $trackTitle,
        public ?string $spectrogramUrl,
    ) {}

    /** What the line and the player call it: "30-second preview", or "Preview" with no length stored. */
    public function label(): string
    {
        return $this->seconds === null ? 'Preview' : $this->seconds.'-second preview';
    }
}
