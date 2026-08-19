@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div class="surface-panel rounded-xl shadow-sm">
    <x-breadcrumb :items="[
        ['label' => 'Home', 'url' => url($site['home_link'] ?? '/'), 'icon' => 'fas fa-home'],
        ['label' => 'Posted By'],
    ]" />

    <x-page-header
        title="Posted By"
        :description="$posterIdentity !== '' ? $posterIdentity : 'No poster identity supplied'"
        icon="fas fa-user"
    />

    @if($results->count() > 0)
        <x-release-results-panel :results="$results" :show-thumbs="true" date-field="adddate" :show-top-pagination="true" />
    @else
        <x-empty-state
            icon="fas fa-user-slash"
            title="No releases found"
            message="No visible releases match this exact Posted By identity."
        />
    @endif
</div>
@endsection
