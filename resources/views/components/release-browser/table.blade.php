<div class="overflow-x-auto">
    <table class="release-browser-table" data-release-table>
        <thead>
            <tr>
                <th><label><input type="checkbox" data-select-all @change="selectAll" aria-label="Select all"><span class="sr-only">Select all</span></label></th>
                <th>Release</th><th>Category</th><th class="text-right">Size</th><th class="text-right">Files</th>
                <th>Added</th><th>Posted</th><th>Stats</th><th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $release)
                @include('components.release-browser.row', ['row' => $release->row_data])
            @endforeach
        </tbody>
    </table>
</div>
