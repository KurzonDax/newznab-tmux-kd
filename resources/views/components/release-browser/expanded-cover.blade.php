@php
    $firstRow = $rows->first()->row_data;
    $entity = $firstRow->entity;
    $title = $entity?->title ?? $firstRow->name;
    $titleUrl = $entity?->titleUrl() ?? route('details', $firstRow->guid);
@endphp
<header class="release-cover-expanded-header">
    <strong>{{ $title }}</strong><span class="text-muted">· {{ $rows->total() }} releases</span>
    <span class="grow"></span>
    <label class="flex items-center gap-1.5"><input type="checkbox" data-select-all @change="selectAll"> Select all on this page</label>
    <x-button variant="success" size="sm" icon="fas fa-download" @click="downloadSelected">Download selected</x-button>
    @if($entity)
        <x-button-link :href="$titleUrl" variant="secondary" size="sm" icon="fas fa-arrow-up-right-from-square">Title page</x-button-link>
    @endif
    <button type="button" class="release-cover-close" @click="closeCover" aria-label="Close releases"><i class="fas fa-xmark" aria-hidden="true"></i></button>
</header>
@include('components.release-browser.table')
<nav class="tv-directory-pager" aria-label="Cover release pages">
    <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
    <button type="button" @click="changeCoverPage" data-cover-page="{{ $rows->currentPage() - 1 }}" @disabled($rows->onFirstPage())>Previous</button>
    <button type="button" @click="changeCoverPage" data-cover-page="{{ $rows->currentPage() + 1 }}" @disabled(!$rows->hasMorePages())>Next</button>
    <x-select width="compact" aria-label="Releases per page" @change="changeCoverPer">
        @foreach([24, 48, 100] as $per)
            <option value="{{ $per }}" @selected($rows->perPage() === $per)>{{ $per }}</option>
        @endforeach
    </x-select>
</nav>
