{{--
    One card: its own form, its own Save button, its own fields and no others.

    The action carries the section and card slug, which is the whole payload contract -- the
    server resolves the card from the registry and rejects anything the card does not own.
--}}
@props([
    'card',
    'section',
    'values' => [],
    'roots' => null,
])

<section id="card-{{ $card->id }}" class="scroll-mt-24">
    <form method="post"
          action="{{ route('admin.settings.update', ['section' => $section->id, 'card' => $card->id]) }}"
          x-data="settingsCard"
          x-on:input="markDirty"
          x-on:change="markDirty">
        @csrf

        <x-panel class="space-y-5">
            <header class="border-b border-gray-200 pb-4 dark:border-gray-700">
                <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    @if($card->icon)
                        <i class="{{ $card->icon }} text-primary-600 dark:text-primary-400" aria-hidden="true"></i>
                    @endif
                    {{ $card->title }}
                </h2>
                @if($card->description)
                    {{-- Card copy is a registry constant, never user input. --}}
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{!! $card->description !!}</p>
                @endif
            </header>

            <div class="grid gap-5 md:grid-cols-2">
                @foreach($card->settings as $definition)
                    <div @class(['md:col-span-2' => $definition->type->spansFullWidth()])>
                        <x-settings.field :definition="$definition" :value="$values[$definition->key] ?? null" :roots="$roots" />
                    </div>
                @endforeach
            </div>

            <footer class="flex items-center justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <span class="text-xs text-gray-500 dark:text-gray-400" x-show="!pristine" x-cloak>Unsaved changes</span>
                <x-button type="submit" icon="fas fa-floppy-disk" ::disabled="pristine">Save</x-button>
            </footer>
        </x-panel>
    </form>
</section>
