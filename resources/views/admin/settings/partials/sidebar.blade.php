{{-- Sub-navigation plus the registry-driven search. Both grow as sections are registered. --}}
<div class="space-y-4">
    <x-panel padding="sm" class="space-y-3">
        <form method="get" action="{{ route('admin.settings.section', ['section' => $section->id]) }}" role="search">
            <x-label for="settings-search">
                <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i>Find a setting
            </x-label>
            <div class="flex gap-2">
                <x-input id="settings-search" name="q" type="search" class="flex-1"
                         value="{{ $searchQuery }}" placeholder="key, label or help text" />
                <x-button type="submit" variant="secondary" size="md" icon="fas fa-arrow-right" aria-label="Search settings" />
            </div>
        </form>

        @if($searchQuery !== '')
            @if($searchResults === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing matches &ldquo;{{ $searchQuery }}&rdquo;.
                </p>
            @else
                <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    @foreach($searchResults as $hit)
                        <li class="py-2">
                            <a class="block rounded px-1 text-primary-700 hover:underline dark:text-primary-300"
                               href="{{ route('admin.settings.section', ['section' => $hit->section->id]) }}#setting-{{ $hit->definition->key }}">
                                {{ $hit->definition->label }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hit->breadcrumb() }}</span>
                                <code class="block text-xs text-gray-400 dark:text-gray-500">{{ $hit->definition->key }}</code>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-panel>

    <x-panel padding="sm">
        <nav aria-label="Settings sections" class="space-y-1">
            @foreach($sections as $navSection)
                <a href="{{ route('admin.settings.section', ['section' => $navSection->id]) }}"
                   @class([
                       'flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition',
                       'bg-primary-600 font-semibold text-white dark:bg-primary-500' => $navSection->id === $section->id,
                       'text-gray-700 hover:bg-primary-50 dark:text-gray-300 dark:hover:bg-primary-900/20' => $navSection->id !== $section->id,
                   ])
                   @if($navSection->id === $section->id) aria-current="page" @endif>
                    <i class="{{ $navSection->icon }} w-4 text-center" aria-hidden="true"></i>{{ $navSection->title }}
                </a>
            @endforeach

            <p class="px-3 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Elsewhere</p>

            <a href="{{ route('admin.backups.index') }}"
               class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-primary-50 dark:text-gray-300 dark:hover:bg-primary-900/20">
                <i class="fas fa-database w-4 text-center" aria-hidden="true"></i>Backups
            </a>
            <a href="{{ route('admin.registrations.index') }}"
               class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 transition hover:bg-primary-50 dark:text-gray-300 dark:hover:bg-primary-900/20">
                <i class="fas fa-user-plus w-4 text-center" aria-hidden="true"></i>Registrations
            </a>
        </nav>
    </x-panel>
</div>
