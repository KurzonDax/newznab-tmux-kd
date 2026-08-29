<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Models\ReleaseVideoClip;
use stdClass;
use Tests\TestCase;

class ReleasePreviewImageViewTest extends TestCase
{
    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coversRoot = $this->makeTempDirectory('nntmux-covers');
        mkdir($this->coversRoot.'/audiosample', 0775, true);
        mkdir($this->coversRoot.'/preview', 0775, true);
        mkdir($this->coversRoot.'/sample', 0775, true);
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    public function test_playable_audio_release_with_a_spectrogram_opens_the_audio_preview(): void
    {
        file_put_contents($this->coversRoot.'/audiosample/audio-guid_spectrum.png', 'png');

        $html = $this->renderResults($this->release([
            'guid' => 'audio-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
            'has_spectrogram' => 1,
            'has_audio_preview' => 1,
            'audio_preview_mime' => 'audio/mpeg',
            'audio_preview_meta' => '30s · MP3 · stream copy',
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-audio-url="'.route('preview.audio', 'audio-guid').'"', $html);
        $this->assertStringContainsString('data-audio-type="audio/mpeg"', $html);
        $this->assertStringContainsString('data-audio-meta="30s · MP3 · stream copy"', $html);
        $this->assertStringContainsString('/covers/audiosample/audio-guid_spectrum.png', $html);
        $this->assertStringContainsString('data-image-title="Audio Preview"', $html);
        $this->assertStringContainsString('fas fa-headphones', $html);
        $this->assertStringNotContainsString('/covers/preview/audio-guid_thumb', $html);
    }

    public function test_browse_preview_triggers_carry_the_full_display_name(): void
    {
        $displayName = 'Readable Release 2026.08.29 v1.2.3 DDP5.1 H.264 With A Deliberately Long Untruncated Ending MKV';
        $html = $this->renderResults($this->release([
            'display_name' => $displayName,
            'searchname' => 'Wrong.Source.Name.That.Must.Not.Be.Used.mkv',
            'haspreview' => 1,
            'jpgstatus' => 1,
        ]));

        $this->assertSame(2, substr_count($html, 'data-release-display-name="'.$displayName.'"'));
        $this->assertStringNotContainsString('data-release-display-name="Wrong.Source.Name', $html);
    }

    public function test_playable_audio_release_without_a_spectrogram_still_has_a_preview_chip(): void
    {
        $html = $this->renderResults($this->release([
            'guid' => 'audio-only-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 0,
            'has_spectrogram' => 0,
            'has_audio_preview' => 1,
            'audio_preview_mime' => 'audio/flac',
            'audio_preview_meta' => '30s · FLAC · FLAC transcode',
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-audio-url="'.route('preview.audio', 'audio-only-guid').'"', $html);
        $this->assertStringContainsString('data-audio-type="audio/flac"', $html);
        $this->assertStringContainsString('data-image-url=""', $html);
        $this->assertStringNotContainsString('_spectrum', $html);
    }

    public function test_video_release_links_the_preview_chip_to_its_generated_preview(): void
    {
        file_put_contents($this->coversRoot.'/preview/video-guid_thumb.jpg', 'jpg');

        $html = $this->renderResults($this->release([
            'guid' => 'video-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('/covers/preview/video-guid_thumb.jpg', $html);
        $this->assertStringContainsString('data-image-title="Preview Image"', $html);
        $this->assertStringNotContainsString('data-audio-url=', $html);
        $this->assertStringNotContainsString('data-audio-type=', $html);
        $this->assertStringNotContainsString('data-audio-meta=', $html);
    }

    public function test_audio_release_with_an_unservable_preview_has_no_preview_chip(): void
    {
        $release = new Release((array) $this->release([
            'guid' => 'unservable-audio-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
        ]));
        $release->setRelation('audioTags', new ReleaseAudioTag([
            'has_preview' => 1,
            'preview_extension' => 'ape',
            'preview_mime' => 'audio/x-ape',
            'has_spectrogram' => 0,
        ]));

        $html = $this->renderResults($release);

        $this->assertStringNotContainsString('class="preview-badge', $html);
        $this->assertStringNotContainsString('data-audio-url=', $html);
    }

    public function test_audio_spectrogram_without_a_playable_clip_falls_back_to_image_only(): void
    {
        file_put_contents($this->coversRoot.'/audiosample/spectrogram-only-guid_spectrum.png', 'png');

        $html = $this->renderResults($this->release([
            'guid' => 'spectrogram-only-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
            'has_spectrogram' => 1,
            'has_audio_preview' => 0,
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('/covers/audiosample/spectrogram-only-guid_spectrum.png', $html);
        $this->assertStringContainsString('data-image-title="Spectrogram"', $html);
        $this->assertStringNotContainsString('data-audio-url=', $html);
    }

    public function test_release_with_a_clip_renders_the_movie_camera_chip_with_video_data(): void
    {
        file_put_contents($this->coversRoot.'/preview/clip-guid_thumb.jpg', 'jpg');

        $html = $this->renderResults($this->release([
            'guid' => 'clip-guid',
            'categories_id' => Category::XXX_XVID,
            'haspreview' => 1,
            'has_video_preview' => 1,
            'video_preview_mime' => 'video/mp4',
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', 'clip-guid').'"', $html);
        $this->assertStringContainsString('data-video-type="video/mp4"', $html);
        $this->assertStringContainsString('fas fa-video', $html);
        $this->assertStringContainsString('/covers/preview/clip-guid_thumb.jpg', $html);
    }

    public function test_release_with_only_a_legacy_ogv_sample_still_gets_the_video_chip(): void
    {
        $html = $this->renderResults($this->release([
            'guid' => 'legacy-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 0,
            'has_video_preview' => 1,
            'video_preview_mime' => 'video/ogg',
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', 'legacy-guid').'"', $html);
        $this->assertStringContainsString('data-video-type="video/ogg"', $html);
        $this->assertStringContainsString('fas fa-video', $html);
    }

    public function test_release_without_video_data_keeps_the_image_only_chip(): void
    {
        file_put_contents($this->coversRoot.'/preview/plain-guid_thumb.jpg', 'jpg');

        $html = $this->renderResults($this->release([
            'guid' => 'plain-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
        ]));

        $this->assertStringNotContainsString('data-video-url=', $html);
        $this->assertStringContainsString('fas fa-image', $html);
    }

    public function test_movies_release_row_renders_the_video_chip(): void
    {
        $html = view('movies.partials.release-item', [
            'release' => $this->release([
                'guid' => 'movie-row-guid',
                'haspreview' => 1,
                'has_video_preview' => 1,
                'video_preview_mime' => 'video/mp4',
                'postdate' => null,
                'adddate' => null,
                'size' => 0,
            ]),
        ])->render();

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', 'movie-row-guid').'"', $html);
        $this->assertStringContainsString('data-video-type="video/mp4"', $html);
        $this->assertStringContainsString('fas fa-video', $html);
    }

    public function test_details_renders_the_video_chip_beside_the_preview_images(): void
    {
        file_put_contents($this->coversRoot.'/preview/details-guid_thumb.jpg', 'jpg');

        $release = Release::factory()->make([
            'guid' => 'details-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
            'jpgstatus' => 0,
            'videostatus' => 1,
        ]);
        $release->setRelation('audioTags', null);
        $release->setRelation('videoClip', new ReleaseVideoClip([
            'extension' => 'mp4',
            'mime' => 'video/mp4',
        ]));

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('data-video-url="'.route('preview.video', 'details-guid').'"', $html);
        $this->assertStringContainsString('data-video-type="video/mp4"', $html);
        $this->assertStringContainsString('fas fa-video', $html);
    }

    public function test_details_preview_triggers_carry_the_full_display_name(): void
    {
        file_put_contents($this->coversRoot.'/preview/details-name-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/details-name-guid_thumb.webp', 'webp');

        $displayName = 'Readable Details Release 2026.08.29 v1.2.3 DDP5.1 H.264 Full Name MKV';
        $release = Release::factory()->make([
            'guid' => 'details-name-guid',
            'display_name' => $displayName,
            'searchname' => 'Wrong.Details.Source.Name.mkv',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
            'jpgstatus' => 1,
            'videostatus' => 1,
        ]);
        $release->setRelation('audioTags', null);
        $release->setRelation('videoClip', new ReleaseVideoClip([
            'extension' => 'mp4',
            'mime' => 'video/mp4',
        ]));

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertSame(3, substr_count($html, 'data-release-display-name="'.$displayName.'"'));
        $this->assertStringNotContainsString('data-release-display-name="Wrong.Details.Source', $html);
    }

    public function test_details_video_chip_uses_the_legacy_mime_without_a_clip_row(): void
    {
        $release = Release::factory()->make([
            'guid' => 'details-legacy-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 0,
            'jpgstatus' => 0,
            'videostatus' => 1,
        ]);
        $release->setRelation('audioTags', null);
        $release->setRelation('videoClip', null);

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertStringContainsString('data-video-url="'.route('preview.video', 'details-legacy-guid').'"', $html);
        $this->assertStringContainsString('data-video-type="video/ogg"', $html);
    }

    public function test_audio_release_does_not_render_a_duplicate_preview_image_on_details(): void
    {
        $release = Release::factory()->make([
            'guid' => 'audio-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
        ]);
        $release->setRelation('audioTags', new ReleaseAudioTag(['has_spectrogram' => 1]));

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertStringNotContainsString('data-image-title="Preview Image"', $html);
        $this->assertStringNotContainsString('/covers/preview/audio-guid_thumb', $html);
    }

    public function test_details_offers_the_fullscreen_view_when_a_full_size_copy_exists(): void
    {
        file_put_contents($this->coversRoot.'/preview/full-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/preview/full-guid.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/full-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/full-guid.webp', 'webp');

        $release = Release::factory()->make([
            'guid' => 'full-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
            'jpgstatus' => 1,
            'videostatus' => 0,
        ]);
        $release->setRelation('audioTags', null);
        $release->setRelation('videoClip', null);

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertStringContainsString('data-full-url="'.url('/covers/preview/full-guid.webp').'"', $html);
        $this->assertStringContainsString('data-full-url="'.url('/covers/sample/full-guid.webp').'"', $html);
    }

    public function test_details_withholds_the_fullscreen_view_for_the_back_catalog(): void
    {
        file_put_contents($this->coversRoot.'/preview/thumb-only-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/thumb-only-guid_thumb.webp', 'webp');

        $release = Release::factory()->make([
            'guid' => 'thumb-only-guid',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 1,
            'jpgstatus' => 1,
            'videostatus' => 0,
        ]);
        $release->setRelation('audioTags', null);
        $release->setRelation('videoClip', null);

        $html = view('details.partials.preview-images', ['release' => $release])->render();

        $this->assertStringContainsString('data-image-title="Preview Image"', $html);
        $this->assertStringContainsString('data-image-title="Sample Image"', $html);
        $this->assertStringNotContainsString('data-full-url', $html);
    }

    public function test_a_browse_row_offers_the_fullscreen_view_when_a_full_size_copy_exists(): void
    {
        file_put_contents($this->coversRoot.'/preview/row-full-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/preview/row-full-guid.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/row-full-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/row-full-guid.webp', 'webp');

        $html = $this->renderResults($this->release([
            'guid' => 'row-full-guid',
            'haspreview' => 1,
            'jpgstatus' => 1,
        ]));

        $this->assertStringContainsString('data-full-url="'.url('/covers/preview/row-full-guid.webp').'"', $html);
        $this->assertStringContainsString('data-full-url="'.url('/covers/sample/row-full-guid.webp').'"', $html);
    }

    public function test_a_browse_row_withholds_the_fullscreen_view_for_the_back_catalog(): void
    {
        file_put_contents($this->coversRoot.'/preview/row-thumb-guid_thumb.webp', 'webp');
        file_put_contents($this->coversRoot.'/sample/row-thumb-guid_thumb.webp', 'webp');

        $html = $this->renderResults($this->release([
            'guid' => 'row-thumb-guid',
            'haspreview' => 1,
            'jpgstatus' => 1,
        ]));

        $this->assertStringContainsString('class="sample-badge', $html);
        $this->assertStringNotContainsString('data-full-url', $html);
    }

    private function renderResults(object $release): string
    {
        return view('components.release-results', ['results' => [$release]])->render();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function release(array $attributes): stdClass
    {
        return (object) ($attributes + [
            'id' => 1,
            'guid' => 'release-guid',
            'display_name' => null,
            'searchname' => 'Release.Name',
            'categories_id' => Category::MOVIE_HD,
            'haspreview' => 0,
            'has_spectrogram' => 0,
            'jpgstatus' => 0,
            'nfostatus' => 0,
            'group_name' => 'alt.binaries.test',
            'postdate' => null,
            'adddate' => null,
            'fromname' => null,
            'totalpart' => 0,
            'grabs' => 0,
            'comments' => 0,
            'size' => 0,
        ]);
    }
}
