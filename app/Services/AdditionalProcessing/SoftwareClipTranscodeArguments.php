<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

class SoftwareClipTranscodeArguments
{
    /**
     * Build the bounded software codec arguments for a browser-safe MP4.
     *
     * @return list<string>
     */
    public function build(bool $copyVideo, bool $hasAudio, int $targetSeconds): array
    {
        $arguments = $copyVideo
            ? ['-c:v', 'copy']
            : ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p'];

        if ($hasAudio) {
            $arguments = [...$arguments, '-c:a', 'aac', '-b:a', '128k'];
        }

        if ($targetSeconds > 0) {
            $arguments = [...$arguments, '-t', (string) $targetSeconds];
        }

        return [...$arguments, '-movflags', '+faststart'];
    }
}
