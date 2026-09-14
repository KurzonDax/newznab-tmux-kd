@php
    $entity = $row->entity;
    $entityUrl = match ($entity?->root) {
        'movies' => route('movie.view', $entity->id),
        'tv' => route('series', $entity->id),
        default => route('details', $row->guid),
    };
@endphp
<tr data-release-row="{{ $row->guid }}">
    <td><input type="checkbox" value="{{ $row->guid }}" data-release-select @change="selectionChanged" aria-label="Select {{ $row->name }}"></td>
    <td class="release-browser-name">
        <div class="flex items-start gap-2.5">
            @if($state->thumbs)
                @php
                    $releaseRoot = \App\Enums\BrowseRoot::fromCategoryId((int) $release->categories_id);
                    $artwork = $entity?->artwork ?? getReleaseCover($release);
                    if ($releaseRoot === \App\Enums\BrowseRoot::Adult) {
                        $artwork = ($release->haspreview == 1 ? getImageAssetUrl('preview', $row->guid.'_thumb', null) : null)
                            ?? ($release->jpgstatus == 1 ? getImageAssetUrl('sample', $row->guid.'_thumb', null) : null);
                    }
                    if ($artwork && str_ends_with($artwork, '/no-cover.png')) {
                        $artwork = null;
                    }
                    $artworkShape = match ($releaseRoot) {
                        \App\Enums\BrowseRoot::Audio => 'square',
                        \App\Enums\BrowseRoot::Adult => 'wide',
                        default => 'tall',
                    };
                @endphp
                <div class="release-browser-art surface-panel-alt" data-shape="{{ $artworkShape }}">
                    @if($artwork)
                        <img src="{{ $artwork }}" alt="" loading="lazy">
                    @else
                        <i class="{{ $releaseRoot->icon() }}" aria-hidden="true"></i><span class="sr-only">No artwork</span>
                    @endif
                </div>
            @endif
            <div class="min-w-0">
                <div class="release-browser-titleline">
                    <a data-release-title href="{{ route('details', $row->guid) }}">{{ $row->name }}</a>
                    <x-release-facts :release="$release" />
                    @if($row->reports > 0)
                        <x-chip variant="warning" icon="fas fa-flag" :href="route('details', $row->guid)" data-report-summary title="Open original report details">Reported ({{ $row->reports }})</x-chip>
                    @endif
                    @if($row->public_responses > 0)
                        <x-chip variant="primary" icon="fas fa-reply" :href="route('details', $row->guid)" data-public-response title="Staff response available on release details">Response</x-chip>
                    @endif
                </div>
                <div class="release-browser-origin">
                    @if($entity)
                        <x-entity-chip :root="$entity->root" :title="$entity->title" :year="$entity->year" :href="$entityUrl" />
                    @endif
                    <x-origin-chip kind="group" :value="$row->group" :href="route('browse.all', ['group' => $row->group])" />
                    <x-origin-chip kind="poster" :value="$row->poster" :href="route('browse.all', ['poster' => $row->poster])" />
                </div>
            </div>
        </div>
    </td>
    <td><x-chip variant="primary" pill>{{ $row->category }}</x-chip></td>
    <td class="text-right tabular-nums whitespace-nowrap">{{ $row->size }}</td>
    <td class="text-right tabular-nums">
        <button type="button" class="filelist-badge text-primary-600 dark:text-primary-400" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}" title="View file list">{{ $row->files }}</button>
    </td>
    <td class="tabular-nums whitespace-nowrap">{{ $row->added }}</td>
    <td class="tabular-nums whitespace-nowrap">{{ $row->posted }}</td>
    <td><div class="flex gap-2 tabular-nums whitespace-nowrap">
        <span title="Grabs"><i class="fas fa-download text-green-600 dark:text-green-400" aria-hidden="true"></i> {{ number_format($row->grabs) }}</span>
        <span title="Comments"><i class="fas fa-comment text-primary-600 dark:text-primary-400" aria-hidden="true"></i> {{ number_format($row->comments) }}</span>
    </div></td>
    <td>
        <div class="release-browser-actions">
            <a data-row-action="download" href="{{ route('getnzb.guid', $row->guid) }}" class="download-nzb release-action release-action-download" title="Download NZB" aria-label="Download NZB"><i class="fas fa-download" aria-hidden="true"></i></a>
            <a data-row-action="details" href="{{ route('details', $row->guid) }}" class="release-action release-action-primary" title="Details" aria-label="Details"><i class="fas fa-info" aria-hidden="true"></i></a>
            <button type="button" data-row-action="basket" data-guid="{{ $row->guid }}" data-in-basket="{{ $row->in_basket ? '1' : '0' }}" @click="toggleBasket" class="release-action release-action-muted" title="{{ $row->in_basket ? 'In basket · click to remove' : 'Add to basket' }}" aria-label="{{ $row->in_basket ? 'Remove from basket' : 'Add to basket' }}"><i class="fas fa-shopping-basket" aria-hidden="true"></i></button>
            <button type="button" data-row-action="report" data-report-release-id="{{ $row->id }}" data-release-display-name="{{ $row->name }}" class="report-trigger release-action release-action-muted" title="Report release" aria-label="Report release"><i class="fas fa-flag" aria-hidden="true"></i></button>
            @if($entity && in_array($entity->root, ['movies', 'tv'], true))
                @php
                    $watchUrl = $entity->root === 'movies'
                        ? url('/mymovies').'?'.http_build_query(['id' => $row->watched ? 'edit' : 'add', 'imdb' => $entity->id])
                        : url('/myshows').'?'.http_build_query(['action' => $row->watched ? 'edit' : 'add', 'id' => $entity->id]);
                @endphp
                <a data-row-action="watch" href="{{ $watchUrl }}" class="release-action release-action-muted" data-watched="{{ $row->watched ? '1' : '0' }}" title="{{ $row->watched ? 'On your watchlist · click to edit' : 'Watch '.$entity->title }}" aria-label="Watch {{ $entity->title }}"><i class="{{ $row->watched ? 'fas' : 'far' }} fa-heart" aria-hidden="true"></i></a>
            @endif
        </div>
    </td>
</tr>
