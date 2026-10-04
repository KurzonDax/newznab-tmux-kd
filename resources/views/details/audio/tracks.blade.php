@php
    /**
     * An Audio release's Tracks tab (docs/proposals/audio-redesign/SPEC.md 5C.2): the total length when
     * every track has one, then #, Title and Length (only when any length is stored), with a disc row
     * before each disc's tracks when the tracks name more than one disc.
     *
     * @var list<\App\Data\AudioTrack> $tracks
     */
    $total = \App\Data\AudioTrack::totalLength($tracks);
    $lengths = \App\Data\AudioTrack::anyLength($tracks);
    $discs = \App\Data\AudioTrack::manyDiscs($tracks);
    $disc = false;
@endphp
@if($total !== '')
    <p class="tv-tracks-total">{{ $total }}</p>
@endif
<table class="tv-release-table tv-tracks">
    <thead>
        <tr><th class="tv-track-number">#</th><th>Title</th>@if($lengths)<th class="tv-track-length">Length</th>@endif</tr>
    </thead>
    <tbody>
        @foreach($tracks as $track)
            @if($discs && $track->disc !== $disc)
                @php($disc = $track->disc)
                <tr class="tv-track-disc"><td colspan="{{ $lengths ? 3 : 2 }}">Disc {{ $track->disc ?? '?' }}</td></tr>
            @endif
            <tr><td class="tv-track-number">{{ $track->number }}</td><td>{{ $track->title }}</td>@if($lengths)<td class="tv-track-length">{{ $track->length() }}</td>@endif</tr>
        @endforeach
    </tbody>
</table>
