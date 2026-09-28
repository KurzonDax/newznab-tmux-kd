@php
    /** @var \App\Data\TvReleaseRow $row */
    $details = route('details', $row->guid);
    // Parts are marked for the prototype comparison (VISUAL-CONTRACT 6), never on a row that starts hidden.
    // The first chip on the page is measured as "chip base"; every other chip by its kind.
    $parts = $batch === null;
    $chipBaseTaken = false;
    $chipPart = function (string $name) use ($row, $chipBaseId, $parts, &$chipBaseTaken): ?string {
        if (! $parts) {
            return null;
        }
        if ($row->id === $chipBaseId && ! $chipBaseTaken) {
            $chipBaseTaken = true;

            return 'chip base';
        }

        return $name;
    };
@endphp
<tr data-release-row @unless($row->hasShow()) data-noshow @endunless @if($batch !== null) data-batch="{{ $batch }}" hidden @endif>
    <td @if($first && $parts) data-part="release row cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
    <td class="tv-art">
        @if($row->hasShow())
            <a href="{{ $details }}" tabindex="-1" aria-hidden="true" data-title="{{ $row->showTitle }}">
                @if($row->poster !== null)
                    <img src="{{ $row->poster }}" alt="" loading="lazy" @if($parts) data-part="row poster" @endif>
                @else
                    <span class="tv-no-poster" @if($parts) data-part="row poster" @endif>{{ $row->showTitle }}</span>
                @endif
            </a>
        @elseif(($card = $row->nameCard()) !== null)
            <a class="tv-placeholder is-card" href="{{ $details }}" tabindex="-1" aria-hidden="true">
                <span class="tv-placeholder-title">{{ $card['title'] }}</span>
                <span class="tv-placeholder-label">{{ $card['episode'] }}</span>
            </a>
        @else
            <a class="tv-placeholder" href="{{ $details }}" tabindex="-1" aria-hidden="true">
                <i class="fas fa-tv" aria-hidden="true"></i>
                <span class="tv-placeholder-label">No poster</span>
            </a>
        @endif
    </td>
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" @if($parts) data-part="release name" @endif>{{ $row->name }}</a>
        @if($row->hasShow())
            <a class="tv-show-line" href="{{ $row->showUrl }}" title="Go to the show" @if($parts) data-part="show line under the name" @endif>{{ $row->showLine() }}</a>
        @endif
        {{-- One chip line: the release chips, then the group and poster pair that never splits (SPEC 3.1). --}}
        @if($row->hasChips() || $row->hasOrigin())
            <div class="tv-chips">
                @include('tv.partials.release-chip-list')
                @if($row->hasOrigin())
                    <span class="tv-origin-pair">
                        @if($row->group !== '')
                            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $row->groupLabel() }}</a>
                        @endif
                        @if($row->uploader !== '')
                            <a class="tv-origin-chip tv-origin-poster" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i><span>{{ $row->uploader }}</span></a>
                        @endif
                    </span>
                @endif
            </div>
        @endif
    </td>
    <td><x-resolution-chip :resolution="$row->resolution" :part="$parts" /></td>
    <td>{{ $row->source }}</td>
    <td class="tv-num">{{ $row->size }}</td>
    <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td>
        @include('tv.partials.release-actions')
    </td>
</tr>
