@php
    $returnTo = $returnTo ?? 'all';
    $returnQuery = $returnQuery ?? [];
@endphp

<tr id="grouprow-{{ $group->id }}" class="group-row hover:bg-gray-50 dark:hover:bg-gray-700">
    <td class="px-4 py-4 text-center">
        <input type="checkbox"
               class="group-checkbox form-checkbox h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
               data-group-id="{{ $group->id }}"
               data-group-name="{{ $group->name }}"
               data-backfill-target="{{ $group->backfill_target }}"
               data-min-files="{{ $group->minfilestoformrelease ?? '' }}"
               data-min-size="{{ $group->minsizetoformrelease ?? '' }}"
               data-active="{{ (int) $group->active }}"
               data-backfill="{{ (int) $group->backfill }}"
               data-route-obfuscated-names="{{ (int) $group->route_obfuscated_names }}"
               data-obfuscated-default-root-category-id="{{ $group->obfuscated_default_root_categories_id ?? '' }}"
               @change="onGroupCheckboxChange()">
    </td>
    <td class="px-6 py-4">
        <a href="{{ route('admin.group-edit', ['id' => $group->id, 'return_to' => $returnTo] + $returnQuery) }}" class="font-semibold text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300">
            {{ str_replace('alt.binaries', 'a.b', $group->name) }}
        </a>
        @if($group->description)
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $group->description }}</div>
        @endif
    </td>
    <td class="px-6 py-4 text-sm">
        <div class="flex flex-col">
            <span class="text-gray-900 dark:text-gray-100" title="{{ userDate($group->first_record_postdate) }}">
                {{ $group->first_record_postdate ? userDate($group->first_record_postdate) : 'Never' }}
            </span>
            <small class="text-gray-500 dark:text-gray-400">
                {{ $group->first_record_postdate ? userDateDiffForHumans($group->first_record_postdate) : 'Never' }}
            </small>
        </div>
    </td>
    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">{{ $group->last_record_postdate }}</td>
    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400" title="{{ userDate($group->last_updated) }}">
        {{ $group->last_updated ? userDateDiffForHumans($group->last_updated) : 'Never' }}
    </td>
    <td class="px-6 py-4 text-center" id="group-{{ $group->id }}">
        @if($group->active == 1)
            <button type="button"
                    @click="handleAction('toggle-group-status', '{{ $group->id }}', '0')"
                    class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-900/50">
                <i class="fas fa-check-circle mr-1"></i>Active
            </button>
        @else
            <button type="button"
                    @click="handleAction('toggle-group-status', '{{ $group->id }}', '1')"
                    class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                <i class="fas fa-times-circle mr-1"></i>Inactive
            </button>
        @endif
    </td>
    <td class="px-6 py-4 text-center" id="backfill-{{ $group->id }}">
        @if($group->backfill == 1)
            <button type="button"
                    @click="handleAction('toggle-backfill', '{{ $group->id }}', '0')"
                    class="inline-flex items-center rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-800 hover:bg-primary-200 dark:bg-primary-900/30 dark:text-primary-300 dark:hover:bg-primary-900/50">
                <i class="fas fa-check-circle mr-1"></i>Enabled
            </button>
        @else
            <button type="button"
                    @click="handleAction('toggle-backfill', '{{ $group->id }}', '1')"
                    class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                <i class="fas fa-times-circle mr-1"></i>Disabled
            </button>
        @endif
    </td>
    <td class="px-6 py-4 text-center">
        <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-200">
            {{ $group->num_releases ?? 0 }}
        </span>
    </td>
    <td class="px-6 py-4 text-center">
        @if(empty($group->minfilestoformrelease))
            <span class="text-gray-400 dark:text-gray-500">n/a</span>
        @else
            <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                {{ $group->minfilestoformrelease }}
            </span>
        @endif
    </td>
    <td class="px-6 py-4 text-center">
        @if(empty($group->minsizetoformrelease))
            <span class="text-gray-400 dark:text-gray-500">n/a</span>
        @else
            <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                {{ human_filesize($group->minsizetoformrelease) }}
            </span>
        @endif
    </td>
    <td class="px-6 py-4 text-center">
        <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-200">
            {{ $group->backfill_target }}
        </span>
    </td>
    <td class="px-6 py-4 text-center">
        @if($group->route_obfuscated_names && $group->obfuscatedDefaultRoot)
            <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-1 text-xs font-semibold text-primary-800 dark:bg-primary-900/30 dark:text-primary-300">
                <i class="fas fa-route mr-1" aria-hidden="true"></i>{{ $group->obfuscatedDefaultRoot->title }}
            </span>
        @elseif($group->obfuscatedDefaultRoot)
            <span class="text-xs text-gray-500 dark:text-gray-400">Off · {{ $group->obfuscatedDefaultRoot->title }}</span>
        @else
            <span class="text-gray-400 dark:text-gray-500">Off</span>
        @endif
    </td>
    <td class="px-6 py-4 text-center" id="groupdel-{{ $group->id }}">
        <div class="flex justify-center gap-1">
            <x-button-link :href="route('admin.group-edit', ['id' => $group->id, 'return_to' => $returnTo] + $returnQuery)"
                           variant="ghost"
                           size="icon"
                           icon="fas fa-pencil"
                           title="Edit this group"
                           aria-label="Edit this group" />
            <x-button type="button"
                      variant="warning"
                      size="icon"
                      icon="fas fa-refresh"
                      @click="handleAction('reset-group', '{{ $group->id }}')"
                      title="Reset this group"
                      aria-label="Reset this group" />
            <x-button type="button"
                      variant="danger"
                      size="icon"
                      icon="fas fa-trash"
                      @click="handleAction('delete-group', '{{ $group->id }}')"
                      title="Delete this group"
                      aria-label="Delete this group" />
            <x-button type="button"
                      variant="danger"
                      size="icon"
                      icon="fas fa-eraser"
                      @click="handleAction('purge-group', '{{ $group->id }}')"
                      title="Purge this group"
                      aria-label="Purge this group" />
        </div>
    </td>
</tr>
