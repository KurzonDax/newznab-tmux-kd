<div data-watchlist-fragment>
@if($find !== '')
    <section class="watchlist-found" aria-label="Titles to add">
        <h2>Add</h2>
        @forelse($found as $item)
            <div class="watchlist-found-row">
                @include('watchlist.artwork')
                <span class="grow"><a href="{{ $item->url }}">{{ $item->title }}</a> <span class="text-muted">{{ $item->year }} @if($item->network)· {{ $item->network }}@endif</span></span>
                <x-watch-button :root="$item->root" :id="$item->id" :title="$item->title" label="Add" />
            </div>
        @empty
            <p class="text-muted">No unfollowed titles match.</p>
        @endforelse
    </section>
@endif
@if($titles->isEmpty())
    <x-empty-state icon="far fa-heart" title="Nothing followed yet." message="Use the search box above, the heart on a cover or release row, or Watch on a title page." :action-url="route('browse', ['parentCategory' => $root->value])" :action-label="'Browse '.$root->label()" />
@else
    <div class="watchlist-entries">
        @foreach($titles as $item)
            <article class="card watchlist-entry">
                @include('watchlist.artwork')
                <div class="watchlist-entry-body">
                    <a class="watchlist-title" href="{{ $item->url }}">{{ $item->title }}</a> <span class="text-muted">{{ $item->year }} @if($item->network)· {{ $item->network }}@endif</span>
                    <p class="watchlist-latest text-muted">Latest: @if($item->latest)<a href="{{ route('details', $item->latest->guid) }}">{{ release_display_name($item->latest) }}</a> · {{ userDateDiffForHumans($item->latest->adddate) }}@else No releases yet.@endif</p>
                    <div class="watchlist-categories">@foreach($item->categories as $category)<x-chip variant="primary">{{ $category }}</x-chip>@endforeach</div>
                </div>
                <div class="watchlist-entry-actions">
                    <x-watch-button :root="$item->root" :id="$item->id" :title="$item->title" :watched="true" label="Edit" />
                    <x-watch-button :root="$item->root" :id="$item->id" :title="$item->title" :watched="true" :remove="true" label="Remove" />
                </div>
            </article>
        @endforeach
    </div>
    @if($titles->hasPages())<div class="p-4">{{ $titles->links() }}</div>@endif
@endif

</div>
