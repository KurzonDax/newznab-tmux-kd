@extends('layouts.main')

@push('modals')
    @include('partials.release-modals')
@endpush

@section('content')
<div @if($blacklistPreview) x-data="posterIdentityBlacklist" @endif>
<div class="surface-panel rounded-xl shadow-sm">
    <x-breadcrumb :items="[
        ['label' => 'Home', 'url' => url($site['home_link'] ?? '/'), 'icon' => 'fas fa-home'],
        ['label' => 'Posted By'],
    ]" />

    <x-page-header
        title="Posted By"
        :description="$posterIdentity !== '' ? $posterIdentity : 'No Posted By identity supplied'"
        icon="fas fa-user"
    >
        @if($posterIdentity !== '' && auth()->user()->hasRole('Admin'))
            <x-slot:actions>
                @if($blacklistRule)
                    <x-button-link
                        :href="route('admin.binaryblacklist-edit', ['id' => $blacklistRule->id])"
                        variant="muted"
                        icon="fas fa-ban"
                    >
                        Blacklisted (rule #{{ $blacklistRule->id }})
                    </x-button-link>
                @else
                    <x-button type="button" variant="danger" icon="fas fa-ban" @click="openConfirmation">
                        Blacklist this poster
                    </x-button>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    @if($showSweepStatus)
        <div x-data="blacklistSweep"
             data-start-url="{{ route('admin.binaryblacklist-sweep.start') }}"
             data-status-url="{{ route('admin.binaryblacklist-sweep.status') }}"
             data-initial-status='@json($sweepStatus)'>
            <x-blacklist-sweep-status />
        </div>
    @endif

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

@if($blacklistPreview)
    <div x-show="confirmationOpen"
         x-cloak
         @keydown.escape.window="closeConfirmation"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
         role="dialog"
         aria-modal="true"
         aria-labelledby="poster-blacklist-title">
        <div class="surface-panel w-full max-w-2xl rounded-xl border border-gray-200 dark:border-gray-700 shadow-xl">
            <div class="border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                <h2 id="poster-blacklist-title" class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    Blacklist this poster
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Confirm the exact rule that will be saved.</p>
            </div>

            <form method="POST" action="{{ route('admin.poster-identity.blacklist') }}" class="space-y-5 p-6">
                @csrf
                <input type="hidden" name="name" value="{{ $posterIdentity }}">
                <input type="hidden" name="preview_token" value="{{ $blacklistPreviewToken }}">

                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-700 dark:text-gray-300">Regex</dt>
                        <dd class="mt-1 rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-gray-900 dark:text-gray-100 break-all">{{ $blacklistPreview['regex'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-700 dark:text-gray-300">Rule</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">Posted By · Type: Black · Status: enabled</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-700 dark:text-gray-300">Group scope</dt>
                        <dd class="mt-1 rounded-lg bg-gray-100 dark:bg-gray-800 p-3 font-mono text-gray-900 dark:text-gray-100 break-all">{{ $blacklistPreview['groupname'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-700 dark:text-gray-300">Description</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $blacklistPreview['description'] }}</dd>
                    </div>
                </dl>

                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors"
                       :class="dangerClasses">
                    <input type="checkbox"
                           name="delete_releases"
                           value="1"
                           x-model="deleteReleases"
                           class="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-800">
                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        Also permanently remove this poster's {{ number_format($results->total()) }} existing releases now
                    </span>
                </label>

                <div class="flex justify-end gap-3">
                    <x-button type="button" variant="muted" @click="closeConfirmation">Cancel</x-button>
                    <x-button type="submit" variant="danger" icon="fas fa-ban">Confirm blacklist</x-button>
                </div>
            </form>
        </div>
    </div>
@endif
</div>
@endsection
