{{--
    Renders one settings-registry entry.

    The registry decides the control, so a page never spells one out: adding a setting is a
    registry edit, not a Blade edit. `value` is the stored value (bytes for size fields, a
    comma-separated list for checkbox sets); `roots` is the root-category collection the
    per-root toggle sets draw from.
--}}
@props([
    'definition',
    'value' => null,
    'roots' => null,
])

@php
    use App\Support\Settings\SettingType;
    use App\Support\SizeUnit;

    $key = $definition->key;
    $type = $definition->type;
    $current = old($key, $value);
    $options = $definition->resolvedOptions();

    $toggleRoots = collect($roots ?? [])
        ->when(
            $definition->eligibleRootIds !== null,
            fn ($collection) => $collection->whereIn('id', $definition->eligibleRootIds),
        )
        ->values();

    $selectedTokens = is_array($current)
        ? array_map('strval', $current)
        : array_values(array_filter(array_map('trim', explode(',', (string) $current)), fn ($token) => $token !== ''));

    $size = $type === SettingType::Size ? SizeUnit::fromBytes(is_array($current) ? 0 : $current) : null;
@endphp

<div id="setting-{{ $key }}" class="scroll-mt-24 space-y-1" data-setting="{{ $key }}">
    <x-label :for="$key">
        @if($definition->icon)
            <i class="{{ $definition->icon }} mr-1" aria-hidden="true"></i>
        @endif
        {{ $definition->label }}
    </x-label>

    @switch($type)
        @case(SettingType::Bool)
        @case(SettingType::Enum)
            <x-select :id="$key" :name="$key">
                @foreach($options as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>
                @endforeach
            </x-select>
            @break

        @case(SettingType::Size)
            <div class="flex gap-2">
                <x-input type="number" step="any" min="0" :id="$key" :name="$key" class="flex-1" :value="$size['value']" />
                <div class="w-28 shrink-0">
                    <x-select :id="$key.'_unit'" :name="$key.'_unit'">
                        @foreach(SizeUnit::UNITS as $unit)
                            <option value="{{ $unit }}" @selected(old($key.'_unit', $size['unit']) === $unit)>{{ $unit }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            @break

        @case(SettingType::Int)
            <div class="flex gap-2">
                <x-input type="number" :id="$key" :name="$key" class="flex-1" :value="$current" />
                @if($definition->unit)
                    <span class="shrink-0 rounded-lg border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $definition->unit }}</span>
                @endif
            </div>
            @break

        @case(SettingType::Date)
            <x-input type="date" :id="$key" :name="$key" :value="$current" />
            @break

        @case(SettingType::Textarea)
            <x-textarea :id="$key" :name="$key" rows="6" :placeholder="$definition->placeholder">{{ $current }}</x-textarea>
            @break

        @case(SettingType::CheckboxSet)
            <div class="grid grid-cols-2 gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-3 lg:grid-cols-4 dark:border-gray-700">
                @foreach($options as $token => $optionLabel)
                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 transition hover:bg-primary-50 dark:hover:bg-primary-900/20">
                        <input type="checkbox" name="{{ $key }}[]" value="{{ $token }}"
                               @checked(in_array((string) $token, $selectedTokens, true))
                               class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $optionLabel }}</span>
                    </label>
                @endforeach
            </div>
            @break

        @case(SettingType::RootToggles)
            <div class="flex flex-wrap gap-2">
                @foreach($toggleRoots as $root)
                    <label class="flex cursor-pointer items-center gap-2 rounded-full border border-gray-300 px-3 py-1.5 transition hover:border-primary-400 dark:border-gray-600 dark:hover:border-primary-500">
                        <input type="checkbox" name="{{ $key }}[{{ $root->id }}]" value="1"
                               @checked($root->{$key})
                               class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $root->title }}</span>
                    </label>
                @endforeach
            </div>
            @break

        @default
            <x-input type="text" :id="$key" :name="$key" :value="$current" :placeholder="$definition->placeholder" />
    @endswitch

    {{-- Help text is a registry constant, never user input, so it may carry inline markup. --}}
    @if($definition->help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{!! $definition->help !!}</p>
    @endif

    {{-- isset(): the hub views are also rendered directly in tests, outside the session-error middleware. --}}
    @php($fieldError = isset($errors) ? $errors->first($key) : '')
    @if($fieldError !== '')
        <p class="text-sm text-red-600 dark:text-red-400"><i class="fas fa-exclamation-circle mr-1" aria-hidden="true"></i>{{ $fieldError }}</p>
    @endif
</div>
