@php
    $entity = $row->entity;
@endphp
<tr data-release-row="{{ $row->guid }}">
    <td><input type="checkbox" value="{{ $row->guid }}" data-release-select @change="selectionChanged" aria-label="Select {{ $row->name }}"></td>
    <td class="release-browser-name">
        <div class="flex items-start gap-2.5">
            @if($state->thumbs)
                @include('components.release-browser.artwork')
            @endif
            <div class="min-w-0">
                <div class="release-browser-titleline">
                    <a data-release-title href="{{ route('details', $row->guid) }}">{{ $row->name }}</a>
                </div>
                @include('components.release-browser.facts')
                @include('components.release-browser.origin')
            </div>
        </div>
    </td>
    <td><x-chip variant="primary" pill>{{ $row->category }}</x-chip></td>
    <td class="text-right tabular-nums whitespace-nowrap">{{ $row->size }}</td>
    <td class="text-right tabular-nums">
        <button type="button" class="filelist-badge text-primary-600 dark:text-primary-400" data-guid="{{ $row->guid }}" data-release-display-name="{{ $row->name }}" title="View file list">{{ $row->files }}</button>
    </td>
    <td class="tabular-nums whitespace-nowrap">{{ $row->added }}</td>
    <td class="tabular-nums whitespace-nowrap">{{ $row->posted }}</td>
    <td><div class="flex gap-2 tabular-nums whitespace-nowrap">
        <span title="Grabs"><i class="fas fa-download text-green-600 dark:text-green-400" aria-hidden="true"></i> {{ number_format($row->grabs) }}</span>
        <span title="Comments"><i class="fas fa-comment text-primary-600 dark:text-primary-400" aria-hidden="true"></i> {{ number_format($row->comments) }}</span>
    </div></td>
    <td>
        @include('components.release-browser.actions')
    </td>
</tr>
