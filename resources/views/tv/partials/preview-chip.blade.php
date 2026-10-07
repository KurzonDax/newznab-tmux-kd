@php
    /**
     * The Preview chip (issue #995): one chip, in Preview's slot before Sample. With a clip it
     * carries a play icon and opens the image dialog's player with the clip's poster; without one
     * it opens the Preview image. Every caller passes $clip explicitly: a Blade include inherits
     * its caller's variables, so a page's clip must never reach another row's chip.
     *
     * @var object{guid: string, name: string, preview: array{thumb: ?string, full: ?string}|null} $row
     * @var array{url: string, type: string, poster: ?string}|null $clip
     * @var \Closure(string): ?string $chipPart
     */
@endphp
@if($clip !== null)
    <x-chip variant="preview" action icon="fas fa-play" class="preview-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-video-url="$clip['url']" :data-video-type="$clip['type']"
            :data-poster-url="$clip['poster']" data-image-title="Video preview" :data-part="$chipPart('Preview chip')" title="Play the video preview">Preview</x-chip>
@elseif($row->preview !== null)
    <x-chip variant="preview" action class="preview-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->preview['thumb'] ?? ''"
            :data-full-url="$row->preview['full']" data-image-title="Image preview" :data-part="$chipPart('Preview chip')" title="View the image preview">Preview</x-chip>
@endif
