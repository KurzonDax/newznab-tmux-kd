@php
    /**
     * The Movies details page's release tables (SPEC 5C.4, 5C.5): the film page's table without the
     * select boxes (Release, Resolution, Source, Size, Files, Posted and the 2 × 2 buttons without
     * Follow; no Grabs column, no same-name line). $current is the guid of the release on this page:
     * its row is tinted, labelled and not a link to itself. $filmLine adds the grey `Title · Year`
     * line under each name (Similar releases). $sortable names the headings' sort attribute:
     * data-sort sorts "All N" on the server, data-similar-sort sorts Similar releases in the browser.
     *
     * @var list<\App\Data\MovieReleaseRow> $rows
     */
    $headings = ['resolution' => 'Resolution', 'size' => 'Size', 'posted' => 'Posted'];
    $heading = static fn (string $key): string => '<button type="button" '.$sortable.'="'.$key.'">'.e($headings[$key]).'<i class="fas fa-sort" aria-hidden="true"></i></button>';
    $sorted = static fn (string $key): string => $sort === $key ? ' aria-sort="'.($ascending ? 'ascending' : 'descending').'"' : '';
    $chipPart = static fn (string $name): ?string => null;
@endphp
<table class="tv-release-table">
    <colgroup><col><col class="tv-col-resolution"><col class="tv-col-source"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-actions"></colgroup>
    <thead>
        <tr>
            <th>Release</th>
            <th{!! $sorted('resolution') !!}>{!! $heading('resolution') !!}</th>
            <th>Source</th>
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
                data-resolution="{{ $row->resolution === \App\Enums\ReleaseResolution::Unknown ? 9 : $row->resolution->value - 1 }}">
                <td class="tv-what" @if($isCurrent) data-part="current release row" @endif>
                    @if($isCurrent)
                        <span class="tv-release-name" title="{{ $row->name }}">{{ $row->name }}</span>
                        <div class="tv-this-release" data-part="current release label">The release on this page</div>
                    @else
                        <a class="tv-release-name" href="{{ route('details', $row->guid) }}" title="{{ $row->name }}">{{ $row->name }}</a>
                    @endif
                    @if($filmLine && $row->hasFilm())
                        <a class="tv-show-line" href="{{ $row->filmUrl() }}" title="Go to the film">{{ $row->filmLine() }}</a>
                    @endif
                    @include('tv.partials.release-chips')
                </td>
                <td><x-resolution-chip :resolution="$row->resolution" :part="false" /></td>
                <td class="tv-nowrap">{{ $row->source }}</td>
                <td class="tv-num">{{ $row->size }}</td>
                <td class="tv-num"><button type="button" class="tv-files filelist-badge" data-guid="{{ $row->guid }}" title="View file list">{{ $row->files }}</button></td>
                <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->postedOn }}</td>
                <td>
                    @include('movies.partials.release-actions', ['parts' => false, 'follow' => false])
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
