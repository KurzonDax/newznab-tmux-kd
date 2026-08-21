<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Models\ReleaseAudioTag;
use Tests\TestCase;

/**
 * The details page itself needs most of the schema to render, so the audio
 * preview partial -- the only part this issue adds -- is rendered on its own.
 */
class DetailsAudioPreviewViewTest extends TestCase
{
    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coversRoot = $this->makeTempDirectory('nntmux-covers');
        mkdir($this->coversRoot.'/audiosample', 0775, true);
        config(['nntmux_settings.covers_path' => $this->coversRoot]);
    }

    public function test_the_details_page_includes_the_audio_preview_partial(): void
    {
        $view = file_get_contents(resource_path('views/details/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("@include('details.partials.audio-preview')", $view);
        $this->assertLessThan(
            strpos($view, "@include('details.partials.movie-info')"),
            strpos($view, "@include('details.partials.audio-preview')"),
            'The audio preview belongs directly after the preview images.'
        );
    }

    public function test_the_details_controller_loads_the_relation_the_partial_reads(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/DetailsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString("loadMissing('audioTags')", $controller);
    }

    public function test_it_renders_a_player_when_the_row_has_a_preview(): void
    {
        $html = $this->renderPartial($this->tag([
            'has_preview' => 1,
            'preview_extension' => 'mp3',
            'preview_mime' => 'audio/mpeg',
            'preview_seconds' => 30,
            'audio_format' => 'MPEG Audio',
        ]));

        $this->assertStringContainsString('<audio controls preload="none"', $html);
        $this->assertStringContainsString(route('preview.audio', 'abc123'), $html);
        $this->assertStringContainsString('type="audio/mpeg"', $html);
        $this->assertStringContainsString('30s · MP3 · stream copy', $html);
        $this->assertStringNotContainsString('_spectrum', $html);
    }

    public function test_it_labels_a_transcoded_preview(): void
    {
        $html = $this->renderPartial($this->tag([
            'has_preview' => 1,
            'preview_extension' => 'flac',
            'preview_mime' => 'audio/flac',
            'preview_seconds' => 30,
            'audio_format' => 'WavPack',
        ]));

        $this->assertStringContainsString('30s · FLAC · FLAC transcode', $html);
    }

    public function test_it_omits_the_encoding_label_when_the_source_format_is_unknown(): void
    {
        $html = $this->renderPartial($this->tag([
            'has_preview' => 1,
            'preview_extension' => 'flac',
            'preview_mime' => 'audio/flac',
            'preview_seconds' => 30,
            'audio_format' => null,
        ]));

        $this->assertStringContainsString('30s · FLAC', $html);
        $this->assertStringNotContainsString('transcode', $html);
        $this->assertStringNotContainsString('stream copy', $html);
    }

    public function test_it_renders_the_spectrogram_when_the_row_and_the_file_agree(): void
    {
        file_put_contents($this->coversRoot.'/audiosample/abc123_spectrum.png', 'png');

        $html = $this->renderPartial($this->tag([
            'has_preview' => 1,
            'preview_extension' => 'flac',
            'preview_mime' => 'audio/flac',
            'preview_seconds' => 30,
            'audio_format' => 'FLAC',
            'has_spectrogram' => 1,
        ]));

        $this->assertStringContainsString('/covers/audiosample/abc123_spectrum.png', $html);
        $this->assertStringContainsString('image-modal-trigger', $html);
    }

    public function test_it_renders_nothing_without_a_preview(): void
    {
        $html = $this->renderPartial($this->tag(['has_preview' => 0]));

        $this->assertSame('', trim(strip_tags($html)));
        $this->assertStringNotContainsString('<audio', $html);
    }

    public function test_it_renders_nothing_for_a_container_the_controller_will_not_serve(): void
    {
        $html = $this->renderPartial($this->tag([
            'has_preview' => 1,
            'preview_extension' => 'ape',
            'preview_mime' => 'audio/x-ape',
        ]));

        $this->assertStringNotContainsString('<audio', $html);
    }

    public function test_it_renders_nothing_without_an_audio_tag_row(): void
    {
        $html = $this->renderPartial(null);

        $this->assertStringNotContainsString('<audio', $html);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tag(array $attributes): ReleaseAudioTag
    {
        return new ReleaseAudioTag($attributes + [
            'has_preview' => 0,
            'has_spectrogram' => 0,
        ]);
    }

    private function renderPartial(?ReleaseAudioTag $tag): string
    {
        $release = new Release(['guid' => 'abc123']);
        $release->setRelation('audioTags', $tag);

        return view('details.partials.audio-preview', ['release' => $release])->render();
    }
}
