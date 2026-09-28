<div class="release-cover-detail-art">@include('components.release-browser.cover-art')</div>
<div class="release-cover-detail-body">
    <header class="release-cover-detail-head">
        <div class="min-w-0">
            <div class="release-cover-detail-title"><a href="{{ $cover->titleUrl }}">{{ $cover->title }}</a> <span class="text-muted font-normal">{{ $cover->year }}</span></div>
            @if($cover->genres !== '')<div class="release-cover-genres">{{ $cover->genres }}</div>@endif
            <div class="release-cover-metadata">
                @foreach($cover->metadata as $value)<x-chip>{{ $value }}</x-chip>@endforeach
            </div>
        </div>
        <span class="release-cover-sub shrink-0">{{ $cover->releaseCount }} releases</span>
    </header>
    <div class="release-cover-detail-releases">
        <span class="eyebrow">Releases · {{ $cover->releaseCount }}</span>
        @foreach($cover->releases as $release)
            @include('components.release-browser.panel')
        @endforeach
        <x-button variant="secondary" size="sm" data-cover-open @click="openCover" aria-expanded="false" aria-controls="cover-expansion">View all {{ $cover->releaseCount }} releases</x-button>
    </div>
</div>
