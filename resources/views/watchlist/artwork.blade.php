<a class="watchlist-artwork" href="{{ $item->url }}" aria-label="{{ $item->title }}">
    @if($item->artwork)<img src="{{ $item->artwork }}" alt="" loading="lazy">@else<i class="{{ $root->icon() }}" aria-hidden="true"></i>@endif
</a>
