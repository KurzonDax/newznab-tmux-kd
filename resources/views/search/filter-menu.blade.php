<details class="search-filter-menu" x-data="searchFilters" @click.outside="close" @keydown.escape.prevent.stop="closeAndFocus">
    <summary class="release-filter-trigger" x-ref="trigger">+ Add filter</summary>
    <form action="{{ route('search') }}" method="GET" class="card search-filter-form">
        @foreach($searchState->parameters as $key => $value)
            @if($key !== 'page')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <x-label for="search-filter-kind" value="Filter" />
        <x-select id="search-filter-kind" name="filter" x-model="kind">
            <option value="cat">Category</option><option value="age">Age</option><option value="size">Size range</option><option value="minc">Completion</option>
            @if(in_array($browserState->root, [\App\Enums\BrowseRoot::All, \App\Enums\BrowseRoot::Movies], true))
                <option value="actors">Actor</option><option value="director">Director</option><option value="title">Movie title</option><option value="plot">Movie plot</option>
            @endif
        </x-select>
        <fieldset x-show="kind === 'cat'" :disabled="kind !== 'cat'">
            <x-label for="search-filter-category" value="Category" />
            <x-select id="search-filter-category" name="filter_value">
                <option value="">Any category</option>
                @foreach($navigationRoots as $navigationRoot)
                    @if($browserState->root === \App\Enums\BrowseRoot::All || $browserState->root === $navigationRoot['root'])
                        <optgroup label="{{ $navigationRoot['root']->label() }}">
                            @foreach($navigationRoot['categories'] as $category)
                                <option value="{{ $category['id'] }}" @selected(($searchState->parameters['cat'] ?? '') === (string) $category['id'])>{{ $category['title'] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                @endforeach
            </x-select>
        </fieldset>
        @foreach(['age' => ['days', '1'], 'size' => ['MB', '0.001']] as $kind => [$unit, $step])
            <fieldset x-show="kind === '{{ $kind }}'" :disabled="kind !== '{{ $kind }}'" x-cloak class="search-filter-range">
                <div><x-label for="search-filter-min-{{ $kind }}" value="Minimum ({{ $unit }})" /><x-input id="search-filter-min-{{ $kind }}" type="number" min="0" step="{{ $step }}" name="filter_value" :value="$searchState->parameters['min'.$kind] ?? ''" /></div>
                <div><x-label for="search-filter-max-{{ $kind }}" value="Maximum ({{ $unit }})" /><x-input id="search-filter-max-{{ $kind }}" type="number" min="0" step="{{ $step }}" name="filter_max" :value="$searchState->parameters['max'.$kind] ?? ''" /></div>
            </fieldset>
        @endforeach
        <fieldset x-show="kind === 'minc'" :disabled="kind !== 'minc'" x-cloak>
            <x-label for="search-filter-completion" value="Completion" />
            <x-select id="search-filter-completion" name="filter_value">
                @foreach(\App\Support\ReleaseCompletion::THRESHOLDS as $value => $label)
                    <option value="{{ $value }}" @selected(($searchState->parameters['minc'] ?? '0') === (string) $value)>{{ $label }}</option>
                @endforeach
            </x-select>
        </fieldset>
        <fieldset x-show="isText" :disabled="!isText" x-cloak>
            <x-label for="search-filter-text" value="Words to match" /><x-input id="search-filter-text" name="filter_value" placeholder="e.g. Emily Blunt" />
        </fieldset>
        <x-button type="submit" size="sm" icon="fas fa-plus">Apply filter</x-button>
    </form>
</details>
