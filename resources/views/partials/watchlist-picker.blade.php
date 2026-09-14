<div x-data="watchlistPicker" data-watchlist-base="{{ url('/watchlist') }}">
    <x-modal name="watchlist" width="picker" close="close">
        <x-slot:title><span x-text="heading">Watchlist</span></x-slot:title>
        <p x-show="loading" role="status">Loading categories…</p>
        <p class="text-sm text-red-600 dark:text-red-400" x-show="error" x-text="error" role="alert"></p>
        <form @submit.prevent="save" id="watchlist-category-form" x-show="current" class="watchlist-picker-form">
            <p>Get releases in these categories:</p>
            <template x-for="category in categories" :key="category.id">
                <label><input type="checkbox" name="categories[]" x-bind:value="category.id" x-model.number="selected" x-bind:disabled="busy"><span x-text="category.label"></span></label>
            </template>
        </form>
        <x-slot:footer>
            <x-button type="submit" form="watchlist-category-form" size="sm" icon="fas fa-heart" ::disabled="busy"><span x-text="saveLabel">Add</span></x-button>
            <x-button variant="secondary" size="sm" @click="close" ::disabled="saving">Cancel</x-button>
            <x-button variant="ghost" size="sm" @click="removeCurrent" x-show="watched" ::disabled="busy" class="ml-auto watchlist-remove">Remove</x-button>
        </x-slot:footer>
    </x-modal>
</div>
