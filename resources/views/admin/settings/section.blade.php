@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <x-panel padding="none">
        <x-page-header :title="$section->title" :description="$section->description" :icon="$section->icon">
            <x-slot:actions>
                <x-settings.pipeline-strip :stages="$stages" :current="$section->stage" />
            </x-slot:actions>
        </x-page-header>
    </x-panel>

    @if(session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
            <p class="text-green-800 dark:text-green-200">
                <i class="fas fa-check-circle mr-2" aria-hidden="true"></i>{{ session('success') }}
            </p>
        </div>
    @endif

    {{-- isset(): this view is also rendered directly in tests, outside the session-error middleware. --}}
    @php($validationErrors = isset($errors) ? $errors->all() : [])
    @if($validationErrors !== [])
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <p class="font-medium text-red-800 dark:text-red-200">
                <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i>Nothing was saved &mdash; please correct the following:
            </p>
            <ul class="mt-2 list-inside list-disc text-sm text-red-800 dark:text-red-200">
                @foreach($validationErrors as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <div>
            @include('admin.settings.partials.sidebar')
        </div>

        <div class="space-y-6">
            @foreach($section->cards as $card)
                <x-settings.card :card="$card" :section="$section" :values="$site" :roots="$roots" />
            @endforeach
        </div>
    </div>
</div>
@endsection
