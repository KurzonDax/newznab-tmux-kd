@php
    $releaseRoot = \App\Enums\BrowseRoot::fromCategoryId((int) $release->categories_id);
    $artwork = $entity?->artwork ?? getReleaseCover($release);
    if ($releaseRoot === \App\Enums\BrowseRoot::Adult) {
        $artwork = ($release->haspreview == 1 ? getImageAssetUrl('preview', $row->guid.'_thumb', null) : null)
            ?? ($release->jpgstatus == 1 ? getImageAssetUrl('sample', $row->guid.'_thumb', null) : null);
    }
    if ($artwork && str_ends_with($artwork, '/no-cover.png')) {
        $artwork = null;
    }
    $artworkShape = match ($releaseRoot) {
        \App\Enums\BrowseRoot::Audio => 'square',
        \App\Enums\BrowseRoot::Adult => 'wide',
        default => 'tall',
    };
@endphp
<div class="release-browser-art surface-panel-alt" data-shape="{{ $artworkShape }}">
    @if($artwork)
        <img src="{{ $artwork }}" alt="" loading="lazy">
    @else
        <i class="{{ $releaseRoot->icon() }}" aria-hidden="true"></i><span class="sr-only">No artwork</span>
    @endif
</div>
