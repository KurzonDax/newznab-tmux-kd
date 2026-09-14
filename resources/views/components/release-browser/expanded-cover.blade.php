@php
    $firstRow = $rows->first()->row_data;
    $entity = $firstRow->entity;
    $title = $entity?->title ?? $firstRow->name;
    $titleUrl = match ($entity?->root) {
        'movies' => route('movie.view', $entity->id),
        'tv' => route('series', $entity->id),
        default => route('details', $firstRow->guid),
    };
@endphp
<header class="release-cover-expanded-header">
    <strong>{{ $title }}</strong><span class="text-muted">· {{ $rows->count() }} releases</span>
    <span class="grow"></span>
    <label class="flex items-center gap-1.5"><input type="checkbox" data-select-all @change="selectAll"> Select all</label>
    <x-button variant="success" size="sm" icon="fas fa-download" @click="downloadSelected">Download selected</x-button>
    @if($entity)
        <x-button-link :href="$titleUrl" variant="secondary" size="sm" icon="fas fa-arrow-up-right-from-square">Title page</x-button-link>
    @endif
    <button type="button" class="release-cover-close" @click="closeCover" aria-label="Close releases"><i class="fas fa-xmark" aria-hidden="true"></i></button>
</header>
@include('components.release-browser.table')
