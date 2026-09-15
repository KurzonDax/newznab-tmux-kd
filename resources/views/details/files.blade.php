@extends('layouts.main')

@section('content')
<section class="card p-4 space-y-4">
    <x-page-header title="Files" :description="$release['searchname']" />
    <p class="text-muted">{{ number_format($total) }} files · Page {{ $page }} of {{ $last_page }}</p>
    <form method="GET" action="{{ route('release.files', ['guid' => $release['guid']]) }}" class="flex items-center gap-2">
        <x-label for="files-per">Files per page</x-label>
        <x-select id="files-per" name="per">
            @foreach([24, 48, 100] as $count)<option value="{{ $count }}" @selected($per === $count)>{{ $count }}</option>@endforeach
        </x-select>
        <x-button type="submit" variant="secondary" size="sm">Apply</x-button>
    </form>
    @if($files)
        <div class="overflow-x-auto">
            <table class="details-files"><thead><tr><th>File name</th><th>Size</th></tr></thead><tbody>
            @foreach($files as $file)
                <tr><td>{{ $file['title'] }}</td><td>{{ number_format($file['size']) }} bytes</td></tr>
            @endforeach
            </tbody></table>
        </div>
    @else
        <p class="text-muted">No files on this page.</p>
    @endif
    <nav aria-label="File pages" class="flex items-center gap-2">
        @if($page > 1)
            <x-button-link variant="secondary" size="sm" :href="route('release.files', ['guid' => $release['guid'], 'page' => $page - 1, 'per' => $per])">Previous</x-button-link>
        @endif
        @if($page < $last_page)
            <x-button-link variant="secondary" size="sm" :href="route('release.files', ['guid' => $release['guid'], 'page' => $page + 1, 'per' => $per])">Next</x-button-link>
        @endif
    </nav>
</section>
@endsection
