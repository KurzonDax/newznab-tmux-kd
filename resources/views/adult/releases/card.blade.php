@php
    /**
     * One card of the Previews wall (docs/proposals/adult-redesign/SPEC.md 5B.4): the thumbnail, the name, the chips
     * (resolution, Completion, Password, Media Info) and the foot (size and date beside the row's buttons).
     *
     * @var \App\Data\AdultReleaseRow $row
     * @var bool $byAdded
     */
    $details = route('details', $row->guid);
    $picture = $row->picture();
    // A preview plays the clip when the release has one; a sample opens the Sample dialog, as the list's picture does.
    $clip = ($picture['kind'] ?? null) === 'preview' ? $row->clip : null;
    $seconds = $clip['seconds'] ?? null;
    $image = ($picture['kind'] ?? null) === 'sample' ? $row->sample : $row->preview;
    // The chip partial names each chip's data-part through $chipPart; the card's chips carry none.
    $chipPart = static fn (string $name): ?string => null;
@endphp
<li class="adult-card" data-release-row>
    {{-- The thumbnail is the dialog's own trigger, focusable and named, since the card has no Preview or Sample chip; a modified click follows the link. --}}
    @if($picture === null)
        <a class="adult-card-thumb tv-placeholder is-no-picture" href="{{ $details }}" tabindex="-1" aria-hidden="true">
            <i class="fas fa-image" aria-hidden="true"></i>
            <span class="tv-placeholder-label">No picture</span>
        </a>
    @elseif($clip !== null)
        <a class="adult-card-thumb has-clip" href="{{ $details }}" data-thumb data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
           data-video-url="{{ $clip['url'] }}" data-video-type="{{ $clip['type'] }}" @if($clip['poster'] !== null) data-poster-url="{{ $clip['poster'] }}" @endif
           data-image-title="Video preview" aria-label="{{ $seconds === null ? 'Play the video clip of '.$row->name : 'Play the '.$seconds.'-second video clip of '.$row->name }}">
            <img src="{{ $picture['url'] }}" alt="" loading="lazy">
            <span class="adult-card-play" aria-hidden="true"><i class="fas fa-play"></i></span>
            <span class="adult-card-clip" aria-hidden="true">{{ $seconds === null ? 'Clip' : 'Clip · '.$seconds.' s' }}</span>
        </a>
    @else
        <a class="adult-card-thumb" href="{{ $details }}" data-thumb data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}"
           data-image-url="{{ $image['thumb'] ?? '' }}" data-full-url="{{ $image['full'] ?? '' }}" data-image-title="{{ $picture['kind'] === 'sample' ? 'Sample image' : 'Image preview' }}"
           aria-label="View the {{ $picture['kind'] }} image of {{ $row->name }}">
            <img src="{{ $picture['url'] }}" alt="" loading="lazy">
        </a>
    @endif
    {{-- Long scene names break after ".", "_", "-" and "]" before they break inside a word. --}}
    <a class="tv-release-name adult-card-name" href="{{ $details }}" title="{{ $row->name }}">{!! preg_replace('/([._\-\]])/', '$1<wbr>', e($row->name)) !!}</a>
    <div class="tv-chips adult-card-chips">
        <x-resolution-chip :resolution="$row->resolution" :part="false" />
        @include('tv.partials.release-chip-list', ['clip' => null, 'chipsOnCard' => true, 'mediaInfoChipTitle' => 'View media info: '.$row->mediaInfo])
    </div>
    <div class="adult-card-foot">
        <div class="adult-card-facts" title="{{ $row->dateTitle }}">
            <span class="adult-card-size">{{ $row->size }}</span>
            <span class="adult-card-date">{{ $byAdded ? 'Added' : 'Posted' }} {{ $row->date }}</span>
        </div>
        @include('movies.partials.release-actions', ['follow' => false, 'parts' => false, 'delete' => true])
    </div>
</li>
