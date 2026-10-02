@php
    /** @var \App\Data\ShelfReleaseRow $row */
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
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" data-part="release name">{{ $row->name }}</a>
        {{-- One chip line: the release chips, then the group and poster pair that never splits (SPEC 5.5). --}}
        @if($row->hasChips() || $row->hasOrigin())
            <div class="tv-chips">
                @include('tv.partials.release-chip-list')
                @include('tv.partials.release-origin')
            </div>
        @endif
    </td>
    <td class="tv-category" title="{{ $row->categoryPath }}">{{ $row->category }}</td>
    <td class="tv-num tv-size">{{ $row->size }}</td>
    <td class="tv-num tv-date" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td>
        @include('movies.partials.release-actions', ['follow' => false, 'parts' => true])
    </td>
</tr>
