@extends('layouts.admin')

@section('content')
<x-admin.card x-data="recoveredReleases">
    <x-admin.page-header :title="$title" icon="fas fa-list" subtitle="Releases created by recovery processing." />
    <form method="GET" action="{{ route('admin.recovered-releases') }}">
        <x-admin.filter-panel>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-80">
                    <x-label for="recovery-group">Group</x-label>
                    <x-select id="recovery-group" name="group" @change="submitFilters($event)">
                        <option value="">All groups</option>
                        @if($group && ! $groups->contains('id', (int) $group))
                            <option value="{{ $group }}" selected>Group #{{ $group }} (unavailable)</option>
                        @endif
                        @foreach($groups as $option)
                            <option value="{{ $option->id }}" @selected((int) $group === (int) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <noscript><x-button type="submit" size="sm">Apply</x-button></noscript>
                @if($group)
                    <x-button-link :href="route('admin.recovered-releases', ['sort' => $sort])" variant="muted" size="sm">Clear filters</x-button-link>
                @endif
            </div>
        </x-admin.filter-panel>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 sm:px-6 py-3 text-sm text-[var(--text-muted)] dark:text-[var(--text-muted-dark)]">
            <span>{{ $releases->firstItem() ?? 0 }}–{{ $releases->lastItem() ?? 0 }} of {{ $releases->total() }} recovered releases</span>
            <div class="flex items-center gap-2">
                <div class="shrink-0"><x-label for="recovery-sort" class="mb-0">Sort by</x-label></div>
                <div class="flex-1 sm:w-52">
                    <x-select id="recovery-sort" name="sort" @change="submitFilters($event)">
                        @foreach(['added-desc' => 'Added · newest first', 'added-asc' => 'Added · oldest first', 'posted-desc' => 'Posted · newest first', 'posted-asc' => 'Posted · oldest first'] as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
        </div>
    </form>
    <x-admin.data-table class="recovered-releases-table">
        <x-slot:head>
            <x-admin.th class="w-1/2">Release</x-admin.th>
            <x-admin.th>Recovery method</x-admin.th>
            <x-admin.th>Size</x-admin.th>
            @foreach(['added' => 'Added', 'posted' => 'Posted'] as $dateSort => $dateLabel)
                <x-admin.th :aria-sort="str_starts_with($sort, $dateSort) ? (str_ends_with($sort, 'asc') ? 'ascending' : 'descending') : 'none'">
                    <a href="{{ route('admin.recovered-releases', ['group' => $group, 'sort' => $dateSort.($sort === $dateSort.'-desc' ? '-asc' : '-desc')]) }}" class="inline-flex items-center gap-1 text-primary-600 dark:text-primary-400 hover:underline">
                        {{ $dateLabel }}
                        <i class="fas {{ $sort === $dateSort.'-asc' ? 'fa-sort-up' : ($sort === $dateSort.'-desc' ? 'fa-sort-down' : 'fa-sort') }}" aria-hidden="true"></i>
                    </a>
                </x-admin.th>
            @endforeach
        </x-slot:head>
        @forelse($releases as $release)
            <tr id="recovered-release-{{ $release->id }}">
                <td>
                    <a href="{{ url('details/'.$release->guid) }}" class="font-medium text-primary-600 dark:text-primary-400 hover:underline break-all">{{ $release->searchname }}</a>
                    <div class="mt-1 text-xs text-[var(--text-muted)] dark:text-[var(--text-muted-dark)]">{{ $release->root_name ?? 'Unknown' }} &gt; {{ $release->category_name ?? 'Unknown category' }} · {{ $release->group_name ?? 'Group unavailable' }}</div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-admin.badge :tone="$release->details->naming === 'Name unresolved' ? 'yellow' : 'gray'">{{ $release->details->naming }}</x-admin.badge>
                        @if($release->multi_media_inventory)
                            <x-admin.badge tone="blue">Multiple media files</x-admin.badge>
                        @endif
                        <x-button variant="ghost" size="sm" id="recovery-toggle-{{ $release->id }}"
                            @click="toggleDetails({{ $release->id }})" ::aria-expanded="isExpanded({{ $release->id }})"
                            aria-controls="recovery-details-{{ $release->id }}" class="font-normal text-primary-600 dark:text-primary-400">
                            Recovery details
                            <i class="fas fa-chevron-down text-[10px] transition-transform" :class="isExpanded({{ $release->id }}) ? 'rotate-180' : ''" aria-hidden="true"></i>
                        </x-button>
                    </div>
                </td>
                <td>{{ $release->details->method }}</td>
                <td class="whitespace-nowrap">{{ number_format($release->size / 1073741824, 2) }} GB</td>
                @foreach([$release->adddate, $release->postdate] as $date)
                    <td class="whitespace-nowrap">
                        <time datetime="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date)->format('M j, Y') }}<span class="block mt-1 text-xs text-[var(--text-muted)] dark:text-[var(--text-muted-dark)]">{{ \Illuminate\Support\Carbon::parse($date)->format('H:i') }}</span></time>
                    </td>
                @endforeach
            </tr>
            <tr id="recovery-details-{{ $release->id }}" x-show="isExpanded({{ $release->id }})" x-cloak>
                <td colspan="5" class="surface-panel-alt">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-7 py-2 max-w-[calc(100vw-5rem)] md:max-w-none" role="region" aria-labelledby="recovery-toggle-{{ $release->id }}">
                        <section class="space-y-4">
                            <div>
                                <h2 class="text-xs font-semibold mb-2">Recovery naming</h2>
                                <p class="text-xs font-medium mb-1">{{ $release->details->naming }}</p>
                                <p class="text-xs leading-relaxed text-[var(--text-muted)] dark:text-[var(--text-muted-dark)]">{{ $release->details->namingNote }} Later processing may have changed the current release name.</p>
                            </div>
                            <div>
                                <h2 class="text-xs font-semibold mb-2">Content inspection</h2>
                                <p class="text-xs font-medium mb-1">{{ $release->details->inspection }}</p>
                                <p class="text-xs leading-relaxed text-[var(--text-muted)] dark:text-[var(--text-muted-dark)]">{{ $release->details->inspectionNote }}</p>
                            </div>
                        </section>
                        <section>
                            <h2 class="text-xs font-semibold mb-2">Recovered contents</h2>
                            <p class="text-xs font-medium mb-2">{{ $release->details->contents }}</p>
                            <ul id="recovery-files-{{ $release->id }}" class="text-xs text-[var(--text-muted)] dark:text-[var(--text-muted-dark)] divide-y divide-[var(--border-default)] dark:divide-[var(--border-default-dark)]">
                                @forelse($release->details->files as $file)
                                    <li class="py-1 break-all" @if($loop->index >= 3 && $file['role'] !== 'index') x-show="allFiles" x-cloak @endif>{{ $file['name'] }}</li>
                                @empty
                                    <li>{{ $release->details->missingFiles }}</li>
                                @endforelse
                            </ul>
                            @if($release->details->payloadFileCount > 3)
                                <x-button variant="ghost" size="sm" @click="toggleFiles" ::aria-expanded="allFiles"
                                    aria-controls="recovery-files-{{ $release->id }}" class="mt-2 font-normal text-primary-600 dark:text-primary-400">
                                    <span x-show="!allFiles">Show all {{ $release->details->payloadFileCount }} {{ $release->details->method === 'RAR' ? 'volume' : 'media' }} filenames</span>
                                    <span x-show="allFiles" x-cloak>Show fewer filenames</span>
                                </x-button>
                            @endif
                        </section>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state icon="fas fa-list" title="No recovered releases" message="No recovered releases match this group." /></td></tr>
        @endforelse
    </x-admin.data-table>
    <x-admin.pagination :paginator="$releases" />
</x-admin.card>
@endsection
