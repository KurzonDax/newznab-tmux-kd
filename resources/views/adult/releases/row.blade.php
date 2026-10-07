@php
    /** @var \App\Data\AdultReleaseRow $row */
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
    $picture = $row->picture();
    $pictureTitle = match (true) {
        ($picture['kind'] ?? null) === 'sample' => 'View sample image',
        $row->clip !== null => 'Play the video preview',
        default => 'View the image preview',
    };
@endphp
<tr data-release-row>
    <td @if($first) data-part="release row cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
    {{-- The picture (SPEC 5.6): a click opens its image dialog, a modified click follows the link; the chip is the accessible way in. --}}
    <td class="tv-art is-picture">
        @if($picture !== null)
            <a href="{{ $details }}" tabindex="-1" aria-hidden="true" data-picture="{{ $picture['kind'] }}" title="{{ $pictureTitle }}">
                <img src="{{ $picture['url'] }}" alt="" loading="lazy" data-part="row picture">
            </a>
        @else
            <a class="tv-placeholder is-no-picture" href="{{ $details }}" tabindex="-1" aria-hidden="true">
                <i class="fas fa-image" aria-hidden="true"></i>
                <span class="tv-placeholder-label">No picture</span>
            </a>
        @endif
    </td>
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" data-part="release name">{{ $row->name }}</a>
        {{-- One chip line: the release chips, the Preview chip playing the clip when there is one, then the group and poster pair that never splits (SPEC 5.5). --}}
        @if($row->hasChips() || $row->hasOrigin())
            <div class="tv-chips">
                @include('tv.partials.release-chip-list', ['clip' => $row->clip])
                @include('tv.partials.release-origin')
            </div>
        @endif
    </td>
    <td><x-resolution-chip :resolution="$row->resolution" :part="true" /></td>
    <td class="tv-num">{{ $row->size }}</td>
    <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td>
        @include('movies.partials.release-actions', ['follow' => false, 'parts' => true])
    </td>
</tr>
