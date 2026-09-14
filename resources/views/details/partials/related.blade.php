<aside class="details-related">
    <section class="card" id="other-releases">
        <h2>Other releases of this title</h2>
        @forelse($otherReleases ?? [] as $other)
            <div class="details-related-release"><a href="{{ route('details', $other->guid) }}" title="{{ release_display_name($other) }}" aria-label="{{ release_display_name($other) }}">{{ $other->related_label }}</a><span class="text-muted">{{ $other->row_data->size }}</span><x-release-completion-chips :release="$other" :show-repair="false" /></div>
        @empty<p class="text-muted">None.</p>@endforelse
        @if($entity?->titleUrl() && ($otherReleaseCount ?? 0) > count($otherReleases ?? []))<a class="details-related-all" href="{{ $entity->titleUrl() }}">View all {{ $otherReleaseCount }} other releases</a>
        @elseif(isset($otherReleases) && $otherReleases instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $otherReleases->hasPages())<div class="mt-3">{{ $otherReleases->links() }}</div>@endif
    </section>
    @if(count($similars ?? []) > 0)
        <section class="card"><h2>Similar releases</h2>
            @foreach($similars as $similar)<div class="details-related-release"><a href="{{ route('details', $similar['guid']) }}">{{ release_display_name($similar) }}</a></div>@endforeach
        </section>
    @endif
</aside>
