@auth
<header class="public-header" data-public-header x-data="publicNavigation" x-on:keydown.window="handleShortcut" x-on:click="headerClick" x-on:click.outside="closeMenus">
    <a href="{{ url('/') }}" class="public-logo"><i class="fas fa-cubes" aria-hidden="true"></i>{{ config('app.name') }}</a>
    <nav class="public-primary-nav" aria-label="Main navigation">
        @foreach($navigationRoots as $navigationRoot)
            @php($root = $navigationRoot['root'])
            @php($list = match ($root) { \App\Enums\BrowseRoot::Tv => 'tv.releases', \App\Enums\BrowseRoot::Movies => 'movies.releases', \App\Enums\BrowseRoot::Adult => 'adult.releases', \App\Enums\BrowseRoot::Books => 'books.releases', \App\Enums\BrowseRoot::Games => 'pc.releases', default => null })
            <div class="public-nav-drop" data-menu-drop="nav-menu-{{ $root->value }}" x-on:focusout="dropFocusLeft">
                <button type="button" class="public-nav-item" data-menu="nav-menu-{{ $root->value }}" x-on:click="toggleMenu" x-on:keydown.down.prevent="openMenuAndFocus" x-bind:aria-expanded="openMenu === 'nav-menu-{{ $root->value }}'" aria-controls="nav-menu-{{ $root->value }}" @if(($navigationCurrent ?? null) === $root) aria-current="true" @endif><span>{{ $root->label() }}</span><i class="fas fa-chevron-down public-nav-chevron" aria-hidden="true"></i></button>
                <div id="nav-menu-{{ $root->value }}" class="card public-menu public-nav-menu" x-cloak x-show="openMenu === 'nav-menu-{{ $root->value }}'" x-on:click="navigate">
                    <a href="{{ $list !== null ? route($list) : url('/browse/'.$root->value) }}" class="public-menu-root">All {{ $root->label() }}</a>
                    <div class="public-menu-divider"></div>
                    @foreach($navigationRoot['categories'] as $category)
                        <a href="{{ $list !== null ? route($list, ['category' => [$category['id']]]) : url('/browse/'.$root->value.'/'.$category['id']) }}">{{ $category['title'] }}</a>
                    @endforeach
                    @if($root === \App\Enums\BrowseRoot::Movies)
                        <div class="public-menu-divider"></div>
                        <a href="{{ route('movies.films') }}">Films</a>
                    @endif
                    @if($root === \App\Enums\BrowseRoot::Tv)
                        <div class="public-menu-divider"></div>
                        <a href="{{ route('tv.shows') }}">TV Shows</a>
                        <a href="{{ route('watchlist', ['tab' => 'tv']) }}">My Shows</a>
                    @endif
                </div>
            </div>
        @endforeach
        <div class="public-nav-drop" data-menu-drop="nav-menu-all" x-on:focusout="dropFocusLeft">
            <button type="button" class="public-nav-item" data-menu="nav-menu-all" x-on:click="toggleMenu" x-on:keydown.down.prevent="openMenuAndFocus" x-bind:aria-expanded="openMenu === 'nav-menu-all'" aria-controls="nav-menu-all" @if(($navigationCurrent ?? null) === \App\Enums\BrowseRoot::All) aria-current="true" @endif><span>All</span><i class="fas fa-chevron-down public-nav-chevron" aria-hidden="true"></i></button>
            <div id="nav-menu-all" class="card public-menu public-nav-menu" x-cloak x-show="openMenu === 'nav-menu-all'" x-on:click="navigate">
                <a href="{{ route('browsegroup') }}">Browse by group</a>
                <a href="{{ route('browse.all') }}">All Releases</a>
            </div>
        </div>
    </nav>
    <form action="{{ url('/search') }}" method="GET" role="search" class="public-search" x-ref="searchForm" data-suggest-url="{{ route('api.search.suggest') }}" x-on:submit="closeSuggestions">
        @php($scopeChoices = ['0' => 'All'] + collect($navigationRoots)->mapWithKeys(fn (array $navigationRoot): array => [(string) $navigationRoot['root']->categoryId() => $navigationRoot['root']->label()])->all())
        @php($scopeValue = is_scalar(request('t')) && array_key_exists((string) request('t'), $scopeChoices) ? (string) request('t') : '0')
        <div class="public-scope" data-search-scope x-on:focusout="scopeFocusLeft">
            <input type="hidden" name="t" value="{{ $scopeValue }}" x-ref="searchScope">
            <button type="button" class="public-scope-button" x-ref="scopeTrigger" x-on:click="toggleScope" x-on:keydown.down.prevent="openScopeAndFocus" aria-haspopup="true" x-bind:aria-expanded="scopeOpen" aria-controls="header-search-scope-menu" aria-label="Search scope: {{ $scopeChoices[$scopeValue] }}"><span x-ref="scopeLabel">{{ $scopeChoices[$scopeValue] }}</span><i class="fas fa-chevron-down public-nav-chevron" aria-hidden="true"></i></button>
            <div id="header-search-scope-menu" class="checkbox-menu-panel public-scope-panel" role="menu" aria-label="Search scope" x-ref="scopePanel" x-cloak x-show="scopeOpen" x-on:keydown="scopeKey">
                @foreach($scopeChoices as $value => $label)
                    <button type="button" class="checkbox-menu-item" role="menuitemradio" data-value="{{ $value }}" aria-checked="{{ (string) $value === $scopeValue ? 'true' : 'false' }}" tabindex="-1" x-on:click="pickScope"><span class="checkbox-menu-dot"></span>{{ $label }}</button>
                @endforeach
            </div>
        </div>
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
        <a href="{{ route('watchlist') }}"><i class="fas fa-bookmark" aria-hidden="true"></i>Following <span data-watchlist-count @if($watchlistCount === 0) hidden @endif>{{ $watchlistCount }}</span></a>
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
