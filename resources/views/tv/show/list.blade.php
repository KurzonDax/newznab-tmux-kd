@php
    /** @var list<\App\Data\TvShowEpisode> $episodes */
    $filtered = $filters->resolutions !== [] || $filters->sources !== [];
    // Under the season tabs (SPEC 3.3): the page-wide empty line, whole-season packs, the Episodes heading
    // and the episodes, then Other releases. Parts are marked once each, on the first element of their
    // kind (VISUAL-CONTRACT 6).
    $closedPartTaken = $openPartTaken = false;
    $tablePart = $open !== [];
@endphp
@if($episodes === [] && $packs === [] && $others === [])
    <p class="tv-empty">No releases{{ $season === null ? '' : ' in this season' }} match {{ $filters->describe([]) }}.</p>
@endif
@if($season !== null)
    <section class="tv-packs is-top">
        <h3>Whole-season packs</h3>
        @if($packs !== [])
            <div class="tv-releases">
                @include('tv.show.releases', ['rows' => $packs, 'pick' => true, 'parts' => ! $tablePart])
            </div>
        @else
            <p class="tv-note">None available for this season{{ $filtered ? ' with your filter' : '' }}.</p>
        @endif
    </section>
@endif
@if($episodes !== [])
    <h3 class="tv-episodes-heading">Episodes</h3>
@endif
@foreach($episodes as $episode)
    @php
        $isOpen = array_key_exists($episode->number, $open);
        $buttonPart = null;
        $firstOpen = $isOpen && ! $openPartTaken;
        if ($firstOpen) {
            $buttonPart = 'releases button, open';
            $openPartTaken = true;
        } elseif (! $isOpen && ! $closedPartTaken) {
            $buttonPart = 'releases button, closed';
            $closedPartTaken = true;
        }
    @endphp
    <div class="tv-episode" data-episode="{{ $episode->number }}" @if($isOpen) data-open @endif>
        <button type="button" data-ep="{{ $episode->number }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" @if($loop->first) data-part="episode row" @endif>
            <span class="tv-episode-number" @if($loop->first) data-part="episode number" @endif>{{ $episode->label() }}</span>
            <span class="tv-episode-title" @if($loop->first) data-part="episode title" @endif>{{ $episode->title }}@if($episode->aired !== '')<small>Aired {{ $episode->aired }}</small>@endif</span>
            <span class="tv-episode-resolutions">
                @foreach($episode->resolutions as $resolution)
                    <x-resolution-chip :resolution="$resolution" :part="false" />
                @endforeach
            </span>
            <span class="tv-episode-sizes">{{ $episode->sizes() }}</span>
            <span class="tv-releases-button" @if($buttonPart !== null) data-part="{{ $buttonPart }}" @endif>{{ $episode->releases }} {{ $episode->releases === 1 ? 'release' : 'releases' }}<i class="fas fa-chevron-down" aria-hidden="true"></i></span>
        </button>
        {{-- Nothing inside while closed, so :empty hides the padding. --}}
        <div class="tv-episode-releases">@if($isOpen)@include('tv.show.releases', ['rows' => $open[$episode->number], 'pick' => true, 'parts' => $firstOpen])@endif</div>
    </div>
@endforeach
@if($others !== [])
    <section class="tv-packs">
        <h3>Other releases</h3>
        <div class="tv-releases">
            @include('tv.show.releases', ['rows' => $others, 'pick' => true, 'parts' => ! $tablePart && ($season === null || $packs === [])])
        </div>
    </section>
@endif
