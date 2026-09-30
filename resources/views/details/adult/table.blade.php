@php
    /**
     * The Adult details page's Similar releases table (docs/proposals/adult-redesign/SPEC.md 5A.4): the
     * Movies details page's table without its Source column (Release with the chip line and the Clip
     * chip, Resolution, Size, Files, Posted and the 2 × 2 buttons without Follow), sorted in the
     * browser by Resolution, Size and Posted. Files reads "—" when none is stored.
     *
     * @var list<\App\Data\AdultReleaseRow> $rows
     */
    $headings = ['resolution' => 'Resolution', 'size' => 'Size', 'posted' => 'Posted'];
    $heading = static fn (string $key): string => '<button type="button" data-similar-sort="'.$key.'">'.e($headings[$key]).'<i class="fas fa-sort" aria-hidden="true"></i></button>';
    $sorted = static fn (string $key): string => $key === 'posted' ? ' aria-sort="descending"' : '';
    // The shared chip partial names each chip's data-part through $chipPart; these tables name none.
    $chipPart = static fn (string $name): ?string => null;
@endphp
<table class="tv-release-table">
    <colgroup><col><col class="tv-col-resolution"><col class="tv-col-size"><col class="tv-col-files"><col class="tv-col-posted"><col class="tv-col-actions"></colgroup>
    <thead>
        <tr>
            <th>Release</th>
            <th{!! $sorted('resolution') !!}>{!! $heading('resolution') !!}</th>
            <th class="tv-num"{!! $sorted('size') !!}>{!! $heading('size') !!}</th>
            <th class="tv-num">Files</th>
            <th class="tv-num"{!! $sorted('posted') !!}>{!! $heading('posted') !!}</th>
            <th><span class="sr-only">Actions</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            <tr data-release-row data-size="{{ (int) $row->bytes }}" data-posted="{{ $row->postedAt }}"
                data-resolution="{{ $row->resolution === \App\Enums\ReleaseResolution::Unknown ? 9 : $row->resolution->value - 1 }}">
                <td class="tv-what">
                    <a class="tv-release-name" href="{{ route('details', $row->guid) }}" title="{{ $row->name }}">{{ $row->name }}</a>
                    @if($row->hasChips())
                        <div class="tv-chips">
                            @include('tv.partials.release-chip-list')
                            @if($row->clip !== null)
                                <x-chip variant="clip" action class="clip-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-video-url="$row->clip['url']" :data-video-type="$row->clip['type']"
                                        data-image-title="Video clip" title="Play the video clip">Clip</x-chip>
                            @endif
                        </div>
                    @endif
                </td>
                <td><x-resolution-chip :resolution="$row->resolution" :part="false" /></td>
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
