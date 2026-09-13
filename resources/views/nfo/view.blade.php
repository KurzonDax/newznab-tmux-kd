@if(isset($modal) && $modal)
    <pre class="nfo-content">{{ $nfo['nfoUTF'] ?? $nfo['nfo'] ?? 'NFO content not available' }}</pre>
@else
    @extends('layouts.main')

    @section('content')
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
        @php
            $nfoCrumbs = [['label' => 'Home', 'url' => url('/')]];
            if (isset($rel)) {
                $nfoCrumbs[] = ['label' => release_display_name($rel), 'url' => url('/details/'.$rel['guid'])];
            }
        @endphp
        <x-breadcrumb :items="$nfoCrumbs" />
        <x-page-header title="NFO File" icon="fas fa-file-lines" />

        <div class="bg-gray-900 text-green-400 p-6 rounded-lg overflow-x-auto font-mono text-sm">
            <pre class="whitespace-pre">{{ $nfo['nfoUTF'] ?? $nfo['nfo'] ?? 'NFO content not available' }}</pre>
        </div>

        @if(isset($rel))
            <div class="mt-4">
                <x-button-link href="{{ url('/details/' . $rel['guid']) }}" icon="fas fa-arrow-left">
                    Back to Release
                </x-button-link>
            </div>
        @endif
    </div>
    @endsection
@endif

