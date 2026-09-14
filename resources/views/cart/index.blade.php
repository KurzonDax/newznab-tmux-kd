@extends('layouts.main')
@push('modals')
    @include('partials.release-modals')
@endpush
@section('content')
<div class="basket-page">
    <x-breadcrumb :items="[['label' => 'Basket']]" />
    <x-page-header title="Basket" />
    <x-release-browser :rows="$results" :state="$browserState" :toolbar="false" :pager="$results->hasPages()" empty-title="Your basket is empty." empty-icon="fas fa-shopping-basket" empty-message="Use the basket button on any release row.">
        @if($results->total() > 0)
            <x-slot:footer>
                <footer class="basket-footer surface-panel-alt">
                    <strong>{{ $results->total() }} in basket</strong><span class="grow"></span>
                    <form method="POST" action="{{ route('basket.empty') }}">@csrf<x-button type="submit" variant="secondary" size="sm">Empty basket</x-button></form>
                    <form method="POST" action="{{ route('basket.download') }}">@csrf<x-button type="submit" variant="success" size="sm" icon="fas fa-download">Download {{ $results->total() }} NZBs</x-button></form>
                </footer>
            </x-slot:footer>
        @endif
    </x-release-browser>
</div>
@endsection
