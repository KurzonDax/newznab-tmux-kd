@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div>
    <x-breadcrumb :items="[['label' => 'Browse', 'url' => route('browse.all')], ['label' => $browserState->root->label(), 'url' => $browserState->watching ? url('/browse/'.$browserState->root->value) : null], ...($browserState->watching ? [['label' => 'Following']] : [])]" />
    <x-page-header :title="$browserTitle" icon="fas fa-compass">
        <x-slot:actions>
            @if($browserState->group !== '' || $browserState->posterIdentity !== '')
                <x-button-link :href="route('browse.all')" variant="ghost" icon="fas fa-xmark">Clear filter</x-button-link>
            @endif
        </x-slot:actions>
    </x-page-header>
    <x-release-browser :rows="$results" :state="$browserState" :sort-options="$sortOptions" />
</div>
@endsection
