<section class="card account-card">
    <h2>Appearance</h2>
    <div class="account-form">
        <fieldset><legend>Theme</legend><div class="account-segment">
            @foreach(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                <button type="button" data-theme="{{ $value }}" @click="setTheme" x-bind:aria-pressed="$store.theme.current === '{{ $value }}'">{{ $label }}</button>
            @endforeach
        </div></fieldset>
        <fieldset><legend>Colour scheme</legend><div class="account-segment">
            @foreach(['blue' => 'Blue', 'emerald' => 'Emerald', 'violet' => 'Violet'] as $value => $label)
                <button type="button" data-scheme="{{ $value }}" @click="setScheme" x-bind:aria-pressed="$store.theme.colorScheme === '{{ $value }}'">{{ $label }}</button>
            @endforeach
        </div></fieldset>
        <div><h3>Default view per root</h3><p class="account-muted">Remembered from what you last used in Browse.</p><dl class="account-view-preferences">
            @foreach($roots as $root)
                <div><dt>{{ $root->label() }}</dt><dd>{{ ucfirst($user->releaseViewPreferences($root->value)['view']) }}</dd></div>
            @endforeach
        </dl></div>
    </div>
</section>
<section class="card account-card">
    <h2>Categories</h2>
    <form method="POST" action="{{ route('account.categories') }}" class="account-form">
        @csrf
        <fieldset><legend>Show categories</legend><div class="account-checkboxes">
            @foreach(['movies' => 'Movies', 'tv' => 'TV', 'audio' => 'Audio', 'console' => 'Console', 'pc' => 'PC / Games', 'books' => 'Books', 'adult' => 'Adult', 'other' => 'Other'] as $key => $label)
                <label><input type="checkbox" name="view{{ $key }}" value="1" @checked($user->hasDirectPermission('view '.$key))>{{ $label }}</label>
            @endforeach
        </div></fieldset>
        <fieldset><legend>Exclude subcategories</legend><p class="account-muted">Hide these categories from your releases.</p>
            @foreach($categoriesWithSubs as $categoryRoot)
                <details class="account-category-group"><summary>{{ $categoryRoot->title }}</summary><div class="account-checkboxes">
                    @foreach($categoryRoot->categories as $category)
                        <label><input type="checkbox" name="excluded_categories[]" value="{{ $category->id }}" @checked(in_array($category->id, $userExcludedCategories))>{{ $category->title }}</label>
                    @endforeach
                </div></details>
            @endforeach
        </fieldset>
        <div><x-button type="submit">Save categories</x-button></div>
    </form>
</section>
