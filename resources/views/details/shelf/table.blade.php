@php
    /**
     * The Books, Console and PC details pages' release table (docs/proposals/books-console-pc-redesign/SPEC.md
     * 5A and 5B): the Adult details page's table with Category in place of Resolution (Release with
     * the game line on Console and the chip line, Category, Size, Files, Posted and the 2 × 2 buttons
     * without Follow). $current is the guid of the release on the page: its row is marked and not a
     * link to itself. $sortable names the headings' sort attribute: data-similar-sort sorts in the
     * browser, data-sort on the server. Each row carries its sub-category's place in the list's
     * Category menu order (data-category) and its id (data-id) for that sort's ties.
     *
     * @var list<\App\Data\ShelfReleaseRow>|list<\App\Data\ConsoleReleaseRow> $rows
     */
    $headings = ['category' => 'Category', 'size' => 'Size', 'posted' => 'Posted'];
    $heading = static fn (string $key): string => '<button type="button" '.$sortable.'="'.$key.'">'.e($headings[$key]).'<i class="fas fa-sort" aria-hidden="true"></i></button>';
    $sorted = static fn (string $key): string => $sort === $key ? ' aria-sort="'.($ascending ? 'ascending' : 'descending').'"' : '';
    // The shared chip partial names each chip's data-part through $chipPart; these tables name none.
    $chipPart = static fn (string $name): ?string => null;
@endphp
<table class="tv-release-table">
    <colgroup><col><col class="tv-col-details-category"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-actions"></colgroup>
    <thead>
        <tr>
            <th>Release</th>
            <th{!! $sorted('category') !!}>{!! $heading('category') !!}</th>
            <th class="tv-num"{!! $sorted('size') !!}>{!! $heading('size') !!}</th>
            <th class="tv-num">Files</th>
            <th class="tv-num"{!! $sorted('posted') !!}>{!! $heading('posted') !!}</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            @php($isCurrent = $current !== null && $row->guid === $current)
            <tr @class(['is-current' => $isCurrent]) @if($isCurrent) aria-current="true" @endif data-release-row data-size="{{ (int) $row->bytes }}" data-posted="{{ $row->postedAt }}"
                data-category="{{ \App\Services\Releases\ShelfReleaseDetails::categoryPosition($row->categoryId) }}" data-id="{{ $row->id }}">
                <td class="tv-what">
                    @if($isCurrent)
                        <span class="tv-release-name" title="{{ $row->name }}">{{ $row->name }}</span>
                        <div class="tv-this-release">The release on this page</div>
                    @else
                        <a class="tv-release-name" href="{{ route('details', $row->guid) }}" title="{{ $row->name }}">{{ $row->name }}</a>
                    @endif
                    @if($row instanceof \App\Data\ConsoleReleaseRow && $row->hasGame())
                        <span class="tv-game-line">{{ $row->gameLine() }}</span>
                    @endif
                    @if($row->hasChips())
                        <div class="tv-chips">
                            @include('tv.partials.release-chip-list')
                        </div>
                    @endif
                </td>
                <td class="tv-category" title="{{ $row->categoryPath }}">{{ $row->category }}</td>
                <td class="tv-num">{{ $row->size }}</td>
                <td class="tv-num">
                    @if($row->hasFileCount())
                        <button type="button" class="tv-files filelist-badge" data-guid="{{ $row->guid }}" title="View file list">{{ $row->files }}</button>
                    @else
                        {{ $row->filesShown() }}
                    @endif
                </td>
                <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->postedOn }}</td>
                <td>
                    @include('movies.partials.release-actions', ['parts' => false, 'follow' => false])
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
