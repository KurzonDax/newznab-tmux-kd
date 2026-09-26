@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div>
    <x-breadcrumb :items="[['label' => 'Browse', 'url' => route('browse.all')], ['label' => $browserState->root->label(), 'url' => $browserState->watching ? url('/browse/'.$browserState->root->value) : null], ...($browserState->watching ? [['label' => 'Watching']] : [])]" />
    <x-page-header :title="$browserTitle" icon="fas fa-compass">
        <x-slot:actions>
            @if($browserState->group !== '' || $browserState->posterIdentity !== '')
                <x-button-link :href="route('browse.all')" variant="ghost" icon="fas fa-xmark">Clear filter</x-button-link>
            @endif
            @if(!$browserState->watching && $browserState->root === \App\Enums\BrowseRoot::Movies)
                <x-button-link :href="route('browse', ['parentCategory' => $browserState->root->value, 'watching' => 1])" variant="secondary" size="sm" icon="fas fa-heart">Only titles I follow</x-button-link>
            @endif
        </x-slot:actions>
    </x-page-header>
    <x-release-browser :rows="$results" :state="$browserState" :filter-options="$filterOptions" :sort-options="$sortOptions" />
</div>
@endsection
