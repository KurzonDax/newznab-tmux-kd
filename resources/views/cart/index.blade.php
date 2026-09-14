@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div>
    <x-breadcrumb :items="[['label' => 'Browse', 'url' => route('browse.all')], ['label' => 'Basket']]" />
    <x-page-header title="Download Basket" icon="fas fa-shopping-basket" />
    <x-release-browser :rows="$results" :state="$browserState" :toolbar="false" empty-title="Your basket is empty." empty-icon="fas fa-shopping-basket" empty-message="Use the basket button on a release to add it here." />
</div>
@endsection
