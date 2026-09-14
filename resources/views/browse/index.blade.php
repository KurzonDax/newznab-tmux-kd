@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div>
    <x-breadcrumb :items="[['label' => 'Browse', 'url' => route('browse.all')], ['label' => $browserState->root->label()]]" />
    <x-page-header :title="$browserTitle" icon="fas fa-compass">
        @if($browserState->group !== '' || $browserState->posterIdentity !== '')
            <x-slot:actions><x-button-link :href="route('browse.all')" variant="ghost" icon="fas fa-xmark">Clear filter</x-button-link></x-slot:actions>
        @endif
    </x-page-header>
    <x-release-browser :rows="$results" :state="$browserState" :filter-options="$filterOptions" :sort-options="$sortOptions" />
</div>
@endsection
