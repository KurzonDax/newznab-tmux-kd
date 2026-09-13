@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="release-detail-page surface-panel rounded-xl shadow-sm p-6">
    <x-breadcrumb :items="[['label' => 'Home', 'url' => url('/')], ['label' => 'Browse', 'url' => route('All')]]" />
    <x-page-header title="Release Details" :description="release_display_name($release)" icon="fas fa-circle-info" />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            @include('details.partials.cover-actions')

            @include('details.partials.preview-images')

            @include('details.partials.audio-preview')

            @include('details.partials.movie-info')

            @include('details.partials.tv-info')

            @include('details.partials.music-info')

            @include('details.partials.game-info')

            @include('details.partials.console-info')

            @include('details.partials.book-info')

            @include('details.partials.anime-info')

            @include('details.partials.password-info')

            @include('details.partials.media-metadata')

            @include('details.partials.predb-info')

            @include('details.partials.comments')
        </div>

        @include('details.partials.info-sidebar')
    </div>
</div>
@endsection

{{-- NFO modal is included globally via layouts.main --}}

@push('scripts')
@include('partials.cart-script')
@endpush
