<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseAudioTag;
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
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    public function test_audio_release_with_a_spectrogram_links_the_preview_chip_to_it(): void
    {
        file_put_contents($this->coversRoot.'/audiosample/audio-guid_spectrum.png', 'png');

        $html = $this->renderResults($this->release([
            'guid' => 'audio-guid',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
            'has_spectrogram' => 1,
        ]));

        $this->assertStringContainsString('class="preview-badge', $html);
        $this->assertStringContainsString('/covers/audiosample/audio-guid_spectrum.png', $html);
        $this->assertStringContainsString('data-image-title="Spectrogram"', $html);
        $this->assertStringNotContainsString('/covers/preview/audio-guid_thumb', $html);
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
    }

    public function test_audio_release_without_a_spectrogram_has_no_preview_chip(): void
    {
        $html = $this->renderResults($this->release([
            'guid' => 'audio-without-spectrogram',
            'categories_id' => Category::MUSIC_MP3,
            'haspreview' => 1,
            'has_spectrogram' => 0,
        ]));

        $this->assertStringNotContainsString('class="preview-badge', $html);
        $this->assertStringNotContainsString('/covers/preview/audio-without-spectrogram_thumb', $html);
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

    private function renderResults(stdClass $release): string
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
