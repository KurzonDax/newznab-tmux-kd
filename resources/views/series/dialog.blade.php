<div class="release-browser tv-show-dialog" x-data="releaseBrowser" data-root="tv" data-per="24" data-last-page="1">
    <header class="tv-show-hero">
        <div class="tv-show-artwork">@if($title->entity->artwork)<img src="{{ $title->entity->artwork }}" alt="">@else<i class="fas fa-tv" aria-hidden="true"></i>@endif</div>
        <div class="min-w-0">
            <h2>{{ $title->entity->title }} {{ $title->entity->year }}</h2>
            <p>{{ $show->genre ?? '' }}</p>
            <div class="release-cover-metadata">@if(isset($title->metadata['Network']))<x-chip>{{ $title->metadata['Network'] }}</x-chip>@endif<x-watch-button root="tv" :id="$show->id" :title="$show->title" :watched="$watched" /></div>
            <p>{{ $title->overview }}</p>@if(!empty($show->status))<x-chip>{{ $show->status }}</x-chip>@endif
            @if($releaseCount === 0)<p>No releases are available for this show.</p>@endif
        </div>
    </header>
    <nav class="tv-show-seasons" aria-label="Seasons">
        @foreach($seasons as $number)<button type="button" data-show-season="{{ $number }}" aria-pressed="{{ (int) $number === $season ? 'true' : 'false' }}">{{ $number > 0 ? 'Season '.$number : 'Specials' }}</button>@endforeach
    </nav>
    <div data-season-host>@include('series.list')</div>
</div>
