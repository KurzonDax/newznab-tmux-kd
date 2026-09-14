<section class="card account-card">
    <h2>API key</h2>
    <div x-data="copyToClipboard" class="account-form"><div><x-label for="api-key">Your API key</x-label><x-input id="api-key" :value="$user->api_token" readonly /></div><div><x-button variant="secondary" size="sm" @click="copy('api-key')" icon="fas fa-copy">Copy</x-button></div></div>
    <form method="POST" action="{{ route('account.api-key') }}" class="mt-3" x-data="confirmForm" data-message="Regenerate your API key? Your apps and RSS feeds will need the new key." @submit.prevent="submit">@csrf<x-button type="submit" variant="secondary" size="sm" icon="fas fa-rotate">Regenerate</x-button></form>
</section>
<section class="card account-card"><h2>API and download usage</h2>
    @include('account.usage', ['id' => 'api', 'label' => 'API requests (24 h)', 'used' => $apiRequests, 'limit' => $apiLimit])
    @include('account.usage', ['id' => 'downloads', 'label' => 'Downloads (24 h)', 'used' => $downloads, 'limit' => $downloadLimit])
</section>
<section class="card account-card" x-data="accountRss" data-rss-base="{{ url('/rss') }}" data-api-token="{{ $user->api_token }}">
    <h2>RSS feed builder</h2>
    <div class="account-form">
        <div><x-label for="feed-type">Feed</x-label><x-select width="compact" id="feed-type" x-model="feed" @change="build"><option value="full-feed">All releases</option><option value="mymovies">My Movies</option><option value="myshows">My Shows</option><option value="cart">Basket</option><option value="category">Category</option></x-select></div>
        <div x-show="feed === 'category'"><x-label for="feed-category">Category</x-label><x-select width="compact" id="feed-category" x-model="category" @change="build">@foreach($categoriesWithSubs as $root)<optgroup label="{{ $root->title }}">@foreach($root->categories as $category)<option value="{{ $category->id }}">{{ $category->title }}</option>@endforeach</optgroup>@endforeach</x-select></div>
        <div><x-label for="feed-count">Results</x-label><x-select width="compact" id="feed-count" x-model="count" @change="build">@foreach([25, 50, 100] as $count)<option value="{{ $count }}">{{ $count }}</option>@endforeach</x-select></div>
        <label class="account-checkbox"><input type="checkbox" x-model="downloads" @change="build">Link directly to NZB downloads</label>
        <div x-data="copyToClipboard"><x-label for="rss-url">Feed URL</x-label><x-input id="rss-url" x-bind:value="url" readonly /><x-button variant="secondary" size="sm" class="mt-2" @click="copy('rss-url')" icon="fas fa-copy">Copy feed URL</x-button></div>
    </div>
</section>
