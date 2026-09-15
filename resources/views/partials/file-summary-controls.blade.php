<div class="flex flex-wrap items-center gap-3 my-3" x-cloak>
    <x-button variant="secondary" size="sm" x-on:click="filesPrevious" ::disabled="filesLoading || filesPage <= 1">Previous</x-button>
    <span class="text-muted" role="status">Page <span x-text="filesPage"></span> of <span x-text="filesLastPage"></span> · <span x-text="filesTotal"></span> files</span>
    <x-button variant="secondary" size="sm" x-on:click="filesNext" ::disabled="filesLoading || filesPage >= filesLastPage">Next</x-button>
    <label class="flex items-center gap-2 text-muted">Files per page
        <x-select x-bind:value="filesPer" x-on:change="filesPerChanged">
            <option value="24">24</option><option value="48">48</option><option value="100">100</option>
        </x-select>
    </label>
</div>
