@php
    /**
     * An Other release's pictures at the top of Overview (docs/proposals/generic-release-lists/SPEC.md 6, the Adult
     * details page's form): the preview plays the clip when there is one, else opens its image; the sample opens at
     * full size. Nothing is printed without a picture.
     *
     * @var \App\Data\GenericReleaseRow $row
     * @var array{url: string, type: string, poster: ?string}|null $clip
     * @var int|null $clipSeconds
     */
    $previewThumb = $row->preview['thumb'] ?? null;
    $sampleThumb = $row->sample['thumb'] ?? null;
    $clip ??= null;
    $clipSeconds ??= null;
@endphp
@if($previewThumb !== null || $sampleThumb !== null)
    <div class="tv-details-pictures">
        @if($previewThumb !== null)
            @if($clip !== null)
                <button type="button" class="tv-details-preview has-clip preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                        data-video-url="{{ $clip['url'] }}" data-video-type="{{ $clip['type'] }}" @if($clip['poster'] !== null) data-poster-url="{{ $clip['poster'] }}" @endif
                        data-image-title="Video preview" aria-label="{{ $clipSeconds === null ? 'Preview, play the video preview' : 'Preview, play the '.$clipSeconds.'-second video preview' }}">
                    <img src="{{ $previewThumb }}" alt="Preview image">
                    <span class="tv-details-play" aria-hidden="true"><i class="fas fa-play"></i></span>
                    <span class="tv-details-picture-label" aria-hidden="true">Preview</span>
                    @if($clipSeconds !== null)
                        <span class="tv-details-picture-label is-clip" aria-hidden="true"><i class="fas fa-play" aria-hidden="true"></i> {{ $clipSeconds }} s</span>
                    @endif
                </button>
            @else
                <button type="button" class="tv-details-preview preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                        data-image-url="{{ $previewThumb }}" data-full-url="{{ $row->preview['full'] }}" data-image-title="Image preview" aria-label="View the image preview">
                    <img src="{{ $previewThumb }}" alt="Preview image">
                    <span class="tv-details-picture-label" aria-hidden="true">Preview</span>
                </button>
            @endif
        @endif
        @if($sampleThumb !== null)
            <button type="button" class="tv-details-preview sample-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
                    data-image-url="{{ $sampleThumb }}" data-full-url="{{ $row->sample['full'] }}" data-image-title="Sample image" data-open-full aria-label="View sample image at full size">
                <img src="{{ $sampleThumb }}" alt="Sample image">
                <span class="tv-details-picture-label" aria-hidden="true">Sample</span>
            </button>
        @endif
    </div>
@endif
