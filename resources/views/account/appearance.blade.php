<section class="card account-card">
    <h2>Appearance</h2>
    <div class="account-form">
        <fieldset><legend>Theme</legend><div class="account-segment">
            @foreach(['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                <button type="button" data-theme="{{ $value }}" @click="setTheme" x-bind:aria-pressed="$store.theme.current === '{{ $value }}'">{{ $label }}</button>
            @endforeach
        </div></fieldset>
    </div>
</section>
<section class="card account-card">
    <h2>Categories</h2>
    <form method="POST" action="{{ route('account.categories') }}" class="account-form">
        @csrf
        <fieldset><legend>Show categories</legend><div class="account-checkboxes">
            @foreach(['movies' => 'Movies', 'tv' => 'TV', 'audio' => 'Audio', 'console' => 'Console', 'pc' => 'PC', 'books' => 'Books', 'adult' => 'Adult', 'other' => 'Other'] as $key => $label)
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
