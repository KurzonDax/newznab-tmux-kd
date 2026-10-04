@php
    /**
     * The preview an Audio release's Overview opens with (docs/proposals/audio-redesign/SPEC.md 5C.1):
     * a line naming what plays, the browser's own player (never autoplaying, loading only its length),
     * and directly under it the spectrogram, which opens in the image dialog with its size and Full
     * size. The block is as wide as the spectrogram, the player as wide as the block.
     *
     * @var \App\Data\AudioReleaseRow $row
     * @var \App\Data\AudioPreview $preview
     */
@endphp
<div class="tv-audio-preview" data-part="audio preview">
    <div class="tv-audio-preview-line">@if($preview->trackTitle !== '')<b>{{ $preview->trackTitle }}</b>@endif<span>{{ $preview->label() }}</span></div>
    <audio controls preload="metadata" src="{{ $preview->url }}" aria-label="{{ $preview->label() }} of {{ $row->name }}"></audio>
    @if($preview->spectrogramUrl !== null)
        <button type="button" class="tv-details-preview preview-badge" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}" data-image-url="{{ $preview->spectrogramUrl }}"
                data-image-title="Spectrogram" aria-label="View the spectrogram"><img src="{{ $preview->spectrogramUrl }}" alt=""><span class="tv-details-picture-label is-top">Spectrogram</span></button>
    @endif
</div>
