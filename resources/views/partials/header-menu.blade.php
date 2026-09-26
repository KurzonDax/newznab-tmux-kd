@auth
<header class="public-header" data-public-header x-data="publicNavigation" x-on:keydown.window="handleShortcut" x-on:click.outside="closeMenus">
    <a href="{{ url('/') }}" class="public-logo"><i class="fas fa-cubes" aria-hidden="true"></i>{{ config('app.name') }}</a>
    <nav class="public-primary-nav" aria-label="Main navigation">
        <button type="button" class="public-nav-item" x-ref="browseTrigger" x-on:click="toggleBrowse" x-bind:aria-expanded="browseOpen" aria-controls="browse-menu" aria-label="Browse categories" @if(request()->is('browse*', 'tv', 'tv/*', 'title*', 'details*') && !request()->boolean('trending')) aria-current="true" @endif>
            <i class="fas fa-compass public-desktop-icon" aria-hidden="true"></i><i class="fas fa-bars public-mobile-icon" aria-hidden="true"></i><span class="public-nav-label">Browse</span><i class="fas fa-chevron-down public-nav-label" aria-hidden="true"></i>
        </button>
        <div id="browse-menu" class="card public-menu public-mega-menu" x-cloak x-show="browseOpen" x-on:click="navigate">
            @foreach($navigationRoots as $navigationRoot)
                @php($root = $navigationRoot['root'])
                <section>
                    @php($isTv = $root === \App\Enums\BrowseRoot::Tv)
                    <a href="{{ $isTv ? route('tv.releases') : url('/browse/'.$root->value) }}" data-browse-root class="public-menu-root"><i class="{{ $root->icon() }}" aria-hidden="true"></i>{{ $root->label() }}</a>
                    @foreach($navigationRoot['categories'] as $category)
                        <a href="{{ $isTv ? route('tv.releases', ['category' => [$category['id']]]) : url('/browse/'.$root->value.'/'.$category['id']) }}">{{ $category['title'] }}</a>
                    @endforeach
                    @if($root === \App\Enums\BrowseRoot::Movies)
                        <a href="{{ route('trending-movies') }}"><i class="fas fa-fire" aria-hidden="true"></i>Trending Movies</a>
                    @endif
                    @if($root === \App\Enums\BrowseRoot::Tv)
                        <a href="{{ route('tv.shows') }}"><i class="fas fa-tv" aria-hidden="true"></i>TV Shows</a>
                        <a href="{{ route('watchlist', ['tab' => 'tv']) }}"><i class="fas fa-heart" aria-hidden="true"></i>My Shows</a>
                    @endif
                </section>
            @endforeach
            <div class="public-menu-footer">
                <a href="{{ route('browse.all') }}">All releases</a><a href="{{ route('browsegroup') }}">Groups</a><a href="{{ route('poster-identity') }}">Poster identities</a>
            </div>
        </div>
        @if(collect($navigationRoots)->contains(fn ($item) => $item['root'] === \App\Enums\BrowseRoot::Movies))
        <a href="{{ route('trending-movies') }}" class="public-nav-item public-wide-nav" @if(request()->is('trending*') || request()->boolean('trending')) aria-current="page" @endif><i class="fas fa-fire" aria-hidden="true"></i>Trending</a>
        @endif
        <a href="{{ route('watchlist') }}" class="public-nav-item public-wide-nav" @if(request()->is('mymovies*', 'myshows*', 'watchlist*')) aria-current="page" @endif><i class="fas fa-heart" aria-hidden="true"></i>Watchlist <span data-watchlist-count @if($watchlistCount === 0) hidden @endif>{{ $watchlistCount }}</span></a>
    </nav>
    <form action="{{ url('/search') }}" method="GET" role="search" class="public-search" x-ref="searchForm" data-suggest-url="{{ route('api.search.suggest') }}" x-on:submit="closeSuggestions">
        <label class="sr-only" for="header-search-scope">Search scope</label>
        <x-select id="header-search-scope" name="t" x-ref="searchScope" x-on:change="fetchSuggestions" control-size="sm" class="public-search-scope">
            <option value="0">All</option>
            @foreach($navigationRoots as $navigationRoot)
                <option value="{{ $navigationRoot['root']->categoryId() }}" @selected(is_scalar(request('t')) && (string) request('t') === (string) $navigationRoot['root']->categoryId())>{{ $navigationRoot['root']->label() }}</option>
            @endforeach
        </x-select>
        <label class="sr-only" for="header-search-input">Search releases</label>
        <x-input id="header-search-input" x-ref="searchInput" type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" placeholder="Search releases… (Press / to search)" class="public-search-input" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="search-suggestions" ::aria-expanded="suggestionsOpen" ::aria-activedescendant="activeSuggestionId" x-on:input="closeSuggestions" x-on:input.debounce.350ms="fetchSuggestions" x-on:keydown="searchKey" />
        <div id="search-suggestions" class="card public-menu public-search-suggestions" role="listbox" aria-label="Search suggestions" x-cloak x-show="suggestionsOpen">
            <template x-for="suggestion in suggestions" x-bind:key="suggestion.id">
                <a x-bind:href="suggestion.url" x-bind:id="suggestion.id" x-bind:aria-selected="suggestionIndex === suggestion.index" role="option" x-text="suggestion.label"></a>
            </template>
        </div>
    </form>
    <a href="{{ route('basket') }}" class="public-nav-item public-basket" aria-label="Basket"><i class="fas fa-shopping-basket" aria-hidden="true"></i><span class="public-nav-label">Basket</span><span class="cart-count" data-basket-count x-text="$store.cart.count">{{ $basketCount }}</span></a>
    <button type="button" class="public-avatar" x-ref="userTrigger" x-on:click="toggleUser" x-bind:aria-expanded="userOpen" aria-controls="user-menu" aria-label="Open user menu">{{ mb_strtoupper(mb_substr($userdata->username, 0, 1)) }}</button>
    <div id="user-menu" class="card public-menu public-user-menu" x-cloak x-show="userOpen" x-on:click="navigate">
        <a href="{{ route('account') }}"><i class="fas fa-user" aria-hidden="true"></i>Account</a>
        <a href="{{ route('watchlist') }}"><i class="fas fa-heart" aria-hidden="true"></i>Watchlist <span data-watchlist-count @if($watchlistCount === 0) hidden @endif>{{ $watchlistCount }}</span></a>
        <a href="{{ route('basket') }}"><i class="fas fa-shopping-basket" aria-hidden="true"></i>Basket <span data-basket-count x-text="$store.cart.count">{{ $basketCount }}</span></a>
        @if(auth()->user()->hasRole('Admin'))
            <a href="{{ route('admin.index') }}"><i class="fas fa-cogs" aria-hidden="true"></i>Admin</a>
        @endif
        <div class="public-menu-divider"></div>
        <div class="public-theme-label">Theme</div>
        <div class="public-theme-options" role="group" aria-label="Theme">
            @foreach(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                <button type="button" x-on:click="$store.theme.set('{{ $value }}')" x-bind:aria-pressed="$store.theme.current === '{{ $value }}'">{{ $label }}</button>
            @endforeach
        </div>
        <div class="public-menu-divider"></div>
        <form action="{{ route('logout') }}" method="POST">@csrf<x-button type="submit" variant="ghost" size="sm" class="public-menu-signout" icon="fas fa-sign-out-alt">Sign out</x-button></form>
    </div>
</header>
@endauth
