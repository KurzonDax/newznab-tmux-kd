@php
    /**
     * The home page's shelves (docs/proposals/home-redesign/SPEC.md 3): per shelf the heading row (name, count line,
     * the two arrows that scroll the rail, See all), the rail of tiles and the slot its open tile's panel is loaded
     * into; the empty states of SPEC 3.5. Also the `shelves` fragment homeShelves draws again after a change.
     *
     * @var list<array{shelf: \App\Enums\HomeShelf, count: string, tiles: list<\App\Data\HomeShelfTile>}> $shelves
     */
@endphp
@forelse($shelves as $view)
    @php($shelf = $view['shelf'])
    <section class="home-shelf" data-shelf="{{ $shelf->value }}">
        <div class="home-shelf-head">
            <h2>{{ $shelf->value }}</h2>
            @if($view['count'] !== '')
                <span class="home-shelf-count" data-shelf-count>{{ $view['count'] }}</span>
            @endif
            <span class="tv-grow"></span>
            <div class="home-rail-nav">
                <button type="button" class="tv-action" data-rail="-1" aria-label="Scroll {{ $shelf->value }} left"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                <button type="button" class="tv-action" data-rail="1" aria-label="Scroll {{ $shelf->value }} right"><i class="fas fa-arrow-right" aria-hidden="true"></i></button>
            </div>
            <a class="home-see-all" href="{{ url($shelf->seeAllPath()) }}">{{ $shelf->seeAllLabel() }}<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        @if($view['tiles'] === [] && $shelf === \App\Enums\HomeShelf::Following)
            <div class="home-empty" data-empty>
                <b>Nothing followed yet.</b>Follow a show or a film from its page, or with the bookmark button on any release row, and its newest releases will be waiting here.<br>
                <a href="{{ route('tv.shows') }}">Browse shows</a> · <a href="{{ route('movies.films') }}">Browse films</a>
            </div>
        @elseif($view['tiles'] === [])
            <p class="home-empty" data-empty>Nothing in {{ $shelf->value }} in the last days.</p>
        @else
            <div class="home-rail" role="group" aria-label="{{ $shelf->description() }}" data-rail-of="{{ $shelf->value }}">
                @foreach($view['tiles'] as $tile)
                    @include('home.tile')
                @endforeach
            </div>
            <div data-panel-slot></div>
        @endif
    </section>
@empty
    <div class="home-empty" data-empty><b>No shelves.</b>Use Shelves to choose which sections appear.</div>
@endforelse
