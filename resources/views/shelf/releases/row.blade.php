@php
    /** @var \App\Data\ShelfReleaseRow|\App\Data\ConsoleReleaseRow|\App\Data\AudioReleaseRow $row */
    $details = route('details', $row->guid);
    // The first chip on the page is measured as "chip base"; every other chip by its kind (the prototype comparison).
    $chipBaseTaken = false;
    $chipPart = function (string $name) use ($row, $chipBaseId, &$chipBaseTaken): string {
        if ($row->id === $chipBaseId && ! $chipBaseTaken) {
            $chipBaseTaken = true;

            return 'chip base';
        }

        return $name;
    };
@endphp
<tr data-release-row>
    <td @if($first) data-part="release row cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
    @if($row instanceof \App\Data\ConsoleReleaseRow)
        {{-- Console's cover, the Movies poster slot (SPEC 5.7); a release with no game or no cover file shows the "No cover" tile. --}}
        <td class="tv-art">
            @if($row->cover !== null)
                <a href="{{ $details }}" tabindex="-1" aria-hidden="true"><img src="{{ $row->cover }}" alt="" loading="eager"></a>
            @else
                <a class="tv-placeholder" href="{{ $details }}" tabindex="-1" aria-hidden="true">
                    <i class="fas fa-image" aria-hidden="true"></i>
                    <span class="tv-placeholder-label">No cover</span>
                </a>
            @endif
        </td>
    @elseif($row instanceof \App\Data\AudioReleaseRow)
        {{-- Audio's square cover slot (SPEC 5.7): no audio cover is stored (SPEC 6.2), so every row shows Adult's dashed "No cover" tile. --}}
        <td class="tv-art is-square">
            <a class="tv-placeholder is-no-picture" href="{{ $details }}" tabindex="-1" aria-hidden="true">
                <i class="fas fa-compact-disc" aria-hidden="true"></i>
                <span class="tv-placeholder-label">No cover</span>
            </a>
        </td>
    @endif
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" data-part="release name">{{ $row->name }}</a>
        @if($row instanceof \App\Data\ConsoleReleaseRow && $row->hasGame())
            <span class="tv-game-line">{{ $row->gameLine() }}</span>
        @elseif($row instanceof \App\Data\AudioReleaseRow && $row->musicLine() !== '')
            <span class="tv-game-line">{{ $row->musicLine() }}</span>
        @endif
        {{-- One chip line: the release chips, then the group and poster pair that never splits (SPEC 5.5). --}}
        @if($row->hasChips() || $row->hasOrigin())
            <div class="tv-chips">
                @include('tv.partials.release-chip-list')
                @if($row instanceof \App\Data\AudioReleaseRow && $row->listen !== null)
                    {{-- Listen (SPEC 5.10), last where Adult's Clip sits: opens the Listen dialog, which plays the preview at once. --}}
                    <x-chip variant="clip" action class="listen-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-audio-url="$row->listen['url']"
                            :data-audio-type="$row->listen['type']" :data-audio-title="$row->listen['title']" :data-audio-artist="$row->listen['artist']"
                            :data-audio-seconds="$row->listen['seconds']" :data-part="$chipPart('Listen chip')" :title="$row->listenTitle()">Listen</x-chip>
                @endif
                @include('tv.partials.release-origin')
            </div>
        @endif
    </td>
    <td class="tv-category" title="{{ $row->categoryPath }}">{{ $row->category }}</td>
    @if($row instanceof \App\Data\ConsoleReleaseRow)
        {{-- The game's genres in IGDB order, up to four lines, the full list in the title (SPEC 5.8). --}}
        @if($row->genres !== '')
            <td class="tv-genre" title="{{ $row->genres }}"><span>{{ $row->genres }}</span></td>
        @else
            <td class="tv-genre"><span>—</span></td>
        @endif
    @elseif($row instanceof \App\Data\AudioReleaseRow)
        {{-- The tags' genres in position order, up to four lines, the full list in the title; "Unknown" for a tag reading so (SPEC 5.8). --}}
        @if($row->genreText() !== '')
            <td class="tv-genre" title="{{ $row->genreText() }}"><span>{{ $row->genreText() }}</span></td>
        @else
            <td class="tv-genre"><span>—</span></td>
        @endif
    @endif
    <td class="tv-num tv-size">{{ $row->size }}</td>
    <td class="tv-num tv-date" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td>
        @include('movies.partials.release-actions', ['follow' => false, 'parts' => true])
    </td>
</tr>
