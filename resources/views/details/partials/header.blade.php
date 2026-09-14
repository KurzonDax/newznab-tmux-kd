<header class="card details-header" data-details-header>
    <div class="details-artwork" data-details-artwork data-artwork-icon="{{ $root->icon() }}">@include('components.release-browser.artwork')</div>
    <div class="details-heading">
        <h1>{{ $row->name }}</h1>
        <div class="details-facts">@include('components.release-browser.facts')<x-origin-chip kind="group" :value="$row->group" :href="route('browse.all', ['group' => $row->group])" /></div>
        <div class="details-subline">
            <x-chip pill>{{ $row->category }}</x-chip><span>{{ $row->size }}</span><span>{{ $row->files }} files</span>
            @if(!\App\Support\ReleaseCompletion::isMeasured($release->completion))<span>Completion not measured</span>@endif
            <span>added {{ $row->added }}</span><span>posted {{ userDate($release->postdate, 'M d, Y H:i') }}</span>
            <span>{{ number_format($row->grabs) }} grabs</span><a href="#comments" data-tab="comments" x-on:click="navigateTab">{{ $commentCount }} comments</a>
        </div>
        <div class="details-actions" x-on:click="navigateTab">
            <x-button-link :href="route('getnzb.guid', $row->guid)" variant="success" icon="fas fa-download" class="download-nzb">Download NZB</x-button-link>
            <x-button variant="secondary" icon="fas fa-shopping-basket" data-details-basket :data-guid="$row->guid" :data-in-basket="$row->in_basket ? '1' : '0'" x-on:click="toggleBasket"><span data-basket-label>{{ $row->in_basket ? 'Remove from basket' : 'Add to basket' }}</span></x-button>
            <x-button-link href="#nfo" data-tab="nfo" variant="secondary" icon="fas fa-file-lines">NFO</x-button-link>
            <x-button-link href="#media" data-tab="media" variant="secondary" icon="fas fa-circle-info">Media info</x-button-link>
            <x-button-link href="#files" data-tab="files" variant="secondary" icon="fas fa-folder-open">Files ({{ $row->files }})</x-button-link>
            @if($entity && in_array($entity->root, ['movies', 'tv'], true))
                @php($watchUrl = $entity->root === 'movies' ? url('/mymovies').'?'.http_build_query(['id' => $row->watched ? 'edit' : 'add', 'imdb' => $entity->id]) : url('/myshows').'?'.http_build_query(['action' => $row->watched ? 'edit' : 'add', 'id' => $entity->id]))
                <x-button-link :href="$watchUrl" :variant="$row->watched ? 'primary' : 'secondary'" :icon="$row->watched ? 'fas fa-heart' : 'far fa-heart'">{{ $row->watched ? 'Watching' : 'Watch' }}</x-button-link>
            @endif
            <x-button variant="ghost" icon="fas fa-flag" class="report-trigger" :data-report-release-id="$row->id" :data-release-display-name="$row->name">Report</x-button>
            @hasanyrole('Admin|Moderator')<x-button-link :href="route('admin.release-edit', ['id' => $row->guid])" variant="secondary" icon="fas fa-pen">Edit release</x-button-link>@endhasanyrole
        </div>
        @if(($failed ?? 0) > 0)<p class="details-failure text-red-600 dark:text-red-400">{{ $failed }} {{ $failed === 1 ? 'user reported' : 'users reported' }} download failure</p>@endif
    </div>
</header>
