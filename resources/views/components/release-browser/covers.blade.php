<div class="release-cover-grid" data-cover-grid data-size="{{ $state->size }}" data-shape="{{ match ($state->root) { \App\Enums\BrowseRoot::Audio => 'square', \App\Enums\BrowseRoot::Adult => 'wide', default => 'tall' } }}">
    @foreach($rows as $cover)
        <article class="release-cover-tile" data-cover-tile="{{ $cover->id }}">
            @if($state->size === 'xl')
                @include('components.release-browser.cover-detail')
            @else
                <button type="button" class="release-cover-open" data-cover-open @click="openCover" aria-expanded="false" aria-controls="cover-expansion" aria-label="Show releases for {{ $cover->title }}">
                    @include('components.release-browser.cover-art')
                    @if($state->root !== \App\Enums\BrowseRoot::Adult)
                        <span data-cover-count class="release-cover-count" title="{{ $cover->releaseCount }} releases">{{ $cover->releaseCount }}</span>
                    @endif
                    <span class="release-cover-body">
                        <span class="release-cover-title">{{ $cover->title }}</span>
                        <span class="release-cover-sub">{{ $cover->identifyingLine }}</span>
                        @if($state->size === 'l')
                            <span class="release-cover-footer">
                                @if($cover->footerBadge !== '')<x-chip>{{ $cover->footerBadge }}</x-chip>@endif
                                <span class="release-cover-total">{{ $cover->footerValue }}</span>
                            </span>
                        @endif
                    </span>
                </button>
                @if($cover->watchUrl)
                    <a class="release-cover-heart" data-cover-watch data-watched="{{ $cover->watched ? '1' : '0' }}" href="{{ $cover->watchUrl }}" aria-label="{{ $cover->watched ? 'Edit Watchlist choices for ' : 'Watch ' }}{{ $cover->title }}"><i class="{{ $cover->watched ? 'fas' : 'far' }} fa-heart" aria-hidden="true"></i></a>
                @endif
            @endif
            @if(in_array($state->root, [\App\Enums\BrowseRoot::Movies, \App\Enums\BrowseRoot::Tv], true) && $state->sort === 'grabs')
                <span class="release-cover-rank" aria-label="Rank {{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}">#{{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}</span>
            @endif
        </article>
    @endforeach
    <div class="release-cover-backdrop" x-show="open" x-cloak @click="closeCover" aria-hidden="true"></div>
    <div id="cover-expansion" class="release-cover-expanded" data-cover-expansion data-modal-dialog hidden tabindex="-1" aria-label="Title releases"></div>
    <template data-cover-error>
        <div class="flex flex-wrap items-center gap-3 p-4 text-sm" role="alert">
            <span>Could not load releases.</span>
            <x-button size="sm" @click="fetchCover">Try again</x-button>
            <x-button size="sm" variant="secondary" @click="closeCover">Close</x-button>
        </div>
    </template>
</div>
