@php
    /**
     * The Books, Console and PC details pages' chip line (docs/proposals/books-console-pc-redesign/SPEC.md
     * 5.5, 5A and 5B): the list rows' chips (completion, Password, media info, NFO; never a resolution,
     * Preview, Sample or Clip chip), then the group and poster outline chips on their own line.
     *
     * @var \App\Data\ShelfReleaseRow|\App\Data\ConsoleReleaseRow $row
     */
    // The shared chip partial names each chip's data-part through $chipPart; this header names none.
    $chipPart = static fn (string $name): ?string => null;
@endphp
<div class="tv-chips tv-details-chips">
    @include('tv.partials.release-chip-list')
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
