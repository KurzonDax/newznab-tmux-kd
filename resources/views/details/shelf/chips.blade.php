@php
    /**
     * The Books, Console, PC and Audio details pages' chip line (docs/proposals/books-console-pc-redesign/SPEC.md
     * 5.5, 5A and 5B; docs/proposals/audio-redesign/SPEC.md 5C.1): the list rows' chips (completion,
     * Password, media info, NFO; never a resolution, Sample or Listen chip, and a Preview chip only
     * for an Audio music video release, playing its clip), then the group and poster outline chips
     * on their own line. Every caller passes $clip, the page's clip or null.
     *
     * @var \App\Data\ShelfReleaseRow|\App\Data\ConsoleReleaseRow|\App\Data\AudioReleaseRow $row
     * @var array{url: string, type: string, poster: ?string}|null $clip
     */
    // The shared chip partial names each chip's data-part through $chipPart; this header names none.
    $chipPart = static fn (string $name): ?string => null;
@endphp
<div class="tv-chips tv-details-chips">
    @include('tv.partials.release-chip-list', ['clip' => $clip])
</div>
@if($row->group !== '' || $row->uploader !== '')
    <div class="tv-chips tv-details-origin">
        @if($row->group !== '')
            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $row->groupLabel() }}</a>
        @endif
        @if($row->uploader !== '')
            <a class="tv-origin-chip" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i>{{ $row->uploader }}</a>
        @endif
    </div>
@endif
