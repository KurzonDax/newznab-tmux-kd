<aside class="details-related">
    @if(count($similars ?? []) > 0)
        <section class="card"><h2>Similar releases</h2>
            @foreach($similars as $similar)<div class="details-related-release"><a href="{{ route('details', $similar['guid']) }}">{{ release_display_name($similar) }}</a></div>@endforeach
        </section>
    @endif
</aside>
