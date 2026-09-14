<div data-episode-releases>
    <header class="release-cover-expanded-header"><strong>Episode releases · {{ $rows->total() }}</strong><span class="grow"></span><button type="button" class="release-cover-close" @click="closeCover" aria-label="Close releases"><i class="fas fa-xmark" aria-hidden="true"></i></button></header>
    <div class="episode-release-panels">@foreach($rows as $release)@include('components.release-browser.panel')@endforeach</div>
    <nav class="tv-directory-pager" aria-label="Episode release pages">
        <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
        <button type="button" @click="changeCoverPage" data-cover-page="{{ $rows->currentPage() - 1 }}" @disabled($rows->onFirstPage())>Previous</button>
        <button type="button" @click="changeCoverPage" data-cover-page="{{ $rows->currentPage() + 1 }}" @disabled(!$rows->hasMorePages())>Next</button>
        <x-select width="compact" aria-label="Releases per page" @change="changeCoverPer">@foreach([24,48,100] as $per)<option value="{{ $per }}" @selected($rows->perPage() === $per)>{{ $per }}</option>@endforeach</x-select>
    </nav>
</div>
