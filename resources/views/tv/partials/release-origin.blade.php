{{--
    The releases lists' group and poster pair that closes the chip line and never splits (TV SPEC 3.1, Movies SPEC 5.5):
    outline chips to the all-categories lists, same tab. A generic list leaves off the chip naming its own context
    ($originOmits: 'group' on a group's list, 'poster' on a poster's list; generic-release-lists SPEC 5.5).
--}}
@php
    $originOmits ??= null;
    $showGroup = $row->group !== '' && $originOmits !== 'group';
    $showPoster = $row->uploader !== '' && $originOmits !== 'poster';
@endphp
@if($showGroup || $showPoster)
    <span class="tv-origin-pair">
        @if($showGroup)
            <a class="tv-origin-chip" href="{{ route('browse.all', ['group' => $row->group]) }}" title="All releases in {{ $row->group }}"><i class="fas fa-users" aria-hidden="true"></i>{{ $row->groupLabel() }}</a>
        @endif
        @if($showPoster)
            <a class="tv-origin-chip tv-origin-poster" href="{{ route('browse.all', ['poster' => $row->uploader]) }}" title="All posts by {{ $row->uploader }}"><i class="fas fa-user" aria-hidden="true"></i><span>{{ $row->uploader }}</span></a>
        @endif
    </span>
@endif
