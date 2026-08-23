<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AudioPreviewEncoding;
use App\Models\ReleaseAudioTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReleaseAudioTagTest extends TestCase
{
    #[Test]
    public function a_flac_source_with_a_flac_preview_is_a_lossless_reencode(): void
    {
        $tag = new ReleaseAudioTag([
            'audio_format' => 'FLAC',
            'preview_extension' => 'flac',
        ]);

        $this->assertSame(AudioPreviewEncoding::FlacReencode, $tag->previewEncoding());
        $this->assertSame('lossless re-encode', $tag->previewEncoding()?->label());
    }

    #[Test]
    public function an_mpeg_audio_source_with_an_mp3_preview_is_a_stream_copy(): void
    {
        $tag = new ReleaseAudioTag([
            'audio_format' => 'MPEG Audio',
            'preview_extension' => 'mp3',
        ]);

        $this->assertSame(AudioPreviewEncoding::StreamCopy, $tag->previewEncoding());
    }

    #[Test]
    public function a_wavpack_source_with_a_flac_preview_is_a_flac_transcode(): void
    {
        $tag = new ReleaseAudioTag([
            'audio_format' => 'WavPack',
            'preview_extension' => 'flac',
        ]);

        $this->assertSame(AudioPreviewEncoding::FlacTranscode, $tag->previewEncoding());
    }
}
