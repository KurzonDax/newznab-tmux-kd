{{-- The chip line under a release name (SPEC section 4); $chipPart names each chip's data-part, or returns null; every caller passes $clip. --}}
@if($row->hasChips())
    <div class="tv-chips">
        @include('tv.partials.release-chip-list', ['clip' => $clip])
    </div>
@endif
