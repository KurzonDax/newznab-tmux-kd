@php
    /**
     * The show page's release table (SPEC 3.3): the releases row minus the artwork, a select box per
     * row when $pick (never a check-all box), and Resolution / Size / Posted / Grabs headers that sort
     * in the browser, Size largest first to begin with. $parts marks this table's first header and cell.
     * On a details page $current is the guid of the release on that page: its row is tinted, labelled
     * and not a link to itself.
     *
     * @var list<\App\Data\TvReleaseRow> $rows
     */
    $names = array_count_values(array_map(static fn ($row): string => $row->name, $rows));
    $sortable = ['resolution' => 'Resolution', 'size' => 'Size', 'posted' => 'Posted', 'grabs' => 'Grabs'];
    $header = static fn (string $key): string => '<button type="button" data-sort="'.$key.'">'.e($sortable[$key]).'<i class="fas fa-sort" aria-hidden="true"></i></button>';
    $chipPart = static fn (string $name): ?string => null;
    $current ??= null;
    @endphp
<table @class(['tv-release-table', 'is-pick' => $pick])>
    <colgroup>@if($pick)<col class="tv-col-select">@endif<col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-count"><col class="tv-col-actions"></colgroup>
    <thead>
        <tr>
            @if($pick)
                <th class="tv-select-cell" @if($parts) data-part="episode release table header" @endif><span class="sr-only">Select</span></th>
            @endif
            <th @if($parts && ! $pick) data-part="episode release table header" @endif>Release</th>
            <th>{!! $header('resolution') !!}</th>
            <th>Source</th>
            <th class="tv-num" aria-sort="descending">{!! $header('size') !!}</th>
            <th class="tv-num">Files</th>
            <th class="tv-num">{!! $header('posted') !!}</th>
            <th class="tv-num">{!! $header('grabs') !!}</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            @php
                $cellPart = $parts && $loop->first;
                $isCurrent = $current !== null && $row->guid === $current;
            @endphp
            <tr @class(['is-current' => $isCurrent]) @if($isCurrent) aria-current="true" @endif data-release-row data-size="{{ (int) $row->bytes }}" data-posted="{{ $row->postedAt }}" data-grabs="{{ $row->grabs }}"
                data-resolution="{{ $row->resolution === \App\Enums\ReleaseResolution::Unknown ? 9 : $row->resolution->value - 1 }}">
                @if($pick)
                    <td class="tv-select-cell" @if($cellPart) data-part="episode release table cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
                @endif
                <td class="tv-what" @if($cellPart && ! $pick) data-part="episode release table cell" @elseif($isCurrent) data-part="current release row" @endif>
                    @if($isCurrent)
                        <span class="tv-release-name" title="{{ $row->name }}">{{ $row->name }}</span>
                        <div class="tv-this-release" data-part="current release label">The release on this page</div>
                    @else
                        <a class="tv-release-name" href="{{ route('details', $row->guid) }}" title="{{ $row->name }}">{{ $row->name }}</a>
                    @endif
                    @if($names[$row->name] > 1)
                        <div class="tv-same-name">Same name posted more than once · this copy by {{ $row->uploader === '' ? 'unknown poster' : $row->uploader }} in {{ $row->group === '' ? 'unknown group' : $row->group }}</div>
                    @endif
                    @include('tv.partials.release-chips', ['parts' => false])
                </td>
                <td><x-resolution-chip :resolution="$row->resolution" :part="false" /></td>
                <td class="tv-nowrap">{{ $row->source }}</td>
                <td class="tv-num">{{ $row->size }}</td>
                <td class="tv-num"><button type="button" class="tv-files filelist-badge" data-guid="{{ $row->guid }}" title="View file list">{{ $row->files }}</button></td>
                <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->postedOn }}</td>
                <td class="tv-num" title="{{ $row->grabs }} grabs · {{ $row->comments }} comments">{{ $row->grabs }}</td>
                <td>
                    @include('tv.partials.release-actions', ['parts' => false])
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
