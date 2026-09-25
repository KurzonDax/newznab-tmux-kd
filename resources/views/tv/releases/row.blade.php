@php
    /** @var \App\Data\TvReleaseRow $row */
    $details = route('details', $row->guid);
    // Parts are marked for the prototype comparison (VISUAL-CONTRACT 6), never on a row that starts hidden.
    // The first chip on the page is measured as "chip base"; every other chip by its kind.
    $parts = $batch === null;
    $chipBaseTaken = false;
    $chipPart = function (string $name) use ($row, $chipBaseId, $parts, &$chipBaseTaken): ?string {
        if (! $parts) {
            return null;
        }
        if ($row->id === $chipBaseId && ! $chipBaseTaken) {
            $chipBaseTaken = true;

            return 'chip base';
        }

        return $name;
    };
@endphp
<tr data-release-row @unless($row->hasShow()) data-noshow @endunless @if($batch !== null) data-batch="{{ $batch }}" hidden @endif>
    <td @if($first && $parts) data-part="release row cell" @endif><input type="checkbox" data-select value="{{ $row->guid }}" aria-label="Select {{ $row->name }}"></td>
    <td class="tv-art">
        @if($row->hasShow())
            <a href="{{ $details }}" tabindex="-1" aria-hidden="true" data-title="{{ $row->showTitle }}">
                @if($row->poster !== null)
                    <img src="{{ $row->poster }}" alt="" loading="lazy" @if($parts) data-part="row poster" @endif>
                @else
                    <span class="tv-no-poster" @if($parts) data-part="row poster" @endif>{{ $row->showTitle }}</span>
                @endif
            </a>
        @endif
    </td>
    <td class="tv-what">
        <a class="tv-release-name" href="{{ $details }}" title="{{ $row->name }}" @if($parts) data-part="release name" @endif>{{ $row->name }}</a>
        @if($row->hasShow())
            <a class="tv-show-line" href="{{ $row->showUrl }}" title="Go to the show" @if($parts) data-part="show line under the name" @endif>{{ $row->showLine() }}</a>
        @endif
        @if($row->hasChips())
            <div class="tv-chips">
                @if($row->completion !== null)
                    <x-chip :variant="'completion-'.$row->completion['band']" :data-part="$chipPart($row->completion['band'] === 'mid' ? 'completion chip' : 'completion chip, '.$row->completion['band'])"
                            :title="$row->completion['percent'].'% of this release\'s articles were seen by the indexer. '.($row->completion['repairing'] ? 'The site may still recover more of it.' : 'Repair has finished: this is as complete as it will get.')">{{ $row->completion['percent'] }}% complete{{ $row->completion['repairing'] ? ' · still repairing' : '' }}</x-chip>
                @endif
                @if($row->passworded)
                    <x-chip variant="password" icon="fas fa-lock" :data-part="$chipPart('password chip')">Password</x-chip>
                @endif
                @if($row->mediaInfo !== null)
                    <x-chip variant="media" action icon="fas fa-circle-info" class="mediainfo-badge" :data-release-id="$row->id" :data-release-display-name="$row->name"
                            :data-part="$chipPart('media info chip')" title="View media info">{{ $row->mediaInfo }}</x-chip>
                @endif
                @if($row->nfo)
                    <x-chip variant="nfo" action class="nfo-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-part="$chipPart('NFO chip')" title="View NFO">NFO</x-chip>
                @endif
                @if($row->preview !== null)
                    <x-chip variant="preview" action class="preview-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->preview['thumb'] ?? ''"
                            :data-full-url="$row->preview['full']" data-image-title="Preview image" :data-part="$chipPart('Preview chip')" title="View preview image">Preview</x-chip>
                @endif
                @if($row->sample !== null)
                    <x-chip variant="sample" action class="sample-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->sample['thumb'] ?? ''"
                            :data-full-url="$row->sample['full']" :data-part="$chipPart('Sample chip')" title="View sample image">Sample</x-chip>
                @endif
            </div>
        @endif
    </td>
    <td><x-resolution-chip :resolution="$row->resolution" :part="$parts" /></td>
    <td>{{ $row->source }}</td>
    <td class="tv-num">{{ $row->size }}</td>
    <td class="tv-num"><button type="button" class="tv-files filelist-badge" data-guid="{{ $row->guid }}" title="View file list" @if($parts) data-part="file count button" @endif>{{ $row->files }}</button></td>
    <td class="tv-num" title="{{ $row->dateTitle }}">{{ $row->date }}</td>
    <td class="tv-num" title="{{ $row->grabs }} grabs · {{ $row->comments }} comments">{{ $row->grabs }}</td>
    <td>
        <div class="tv-actions">
            <a href="{{ route('getnzb.guid', $row->guid) }}" class="tv-action tv-action-download download-nzb" title="Download NZB" aria-label="Download NZB" @if($parts) data-part="row action: download" @endif><i class="fas fa-download" aria-hidden="true"></i></a>
            <button type="button" class="tv-action" data-copy-nzb="{{ $row->guid }}" title="Copy NZB link for SABnzbd or NZBGet" aria-label="Copy NZB link for SABnzbd or NZBGet" @if($parts) data-part="row action button" @endif><i class="fas fa-link" aria-hidden="true"></i></button>
            <button type="button" class="tv-action" data-cart="{{ $row->guid }}" aria-pressed="{{ $row->inCart ? 'true' : 'false' }}" title="{{ $row->inCart ? 'In cart · click to remove' : 'Add to cart' }}" aria-label="{{ $row->inCart ? 'Remove from cart' : 'Add to cart' }}"><i class="fas fa-cart-shopping" aria-hidden="true"></i></button>
            @if($row->hasShow())
                <button type="button" class="tv-action" data-watch-picker="{{ route('watchlist.picker', ['root' => 'tv', 'id' => $row->showId]) }}" data-watch-key="tv:{{ $row->showId }}" data-watch-title="{{ $row->showTitle }}" data-watched="{{ $row->watched ? '1' : '0' }}"
                        title="{{ $row->watched ? 'On your watchlist · click to edit' : 'Watch this show' }}" aria-label="{{ $row->watched ? 'Edit Watchlist choices for ' : 'Watch ' }}{{ $row->showTitle }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
            @else
                <span class="tv-action tv-action-slot" aria-hidden="true"></span>
            @endif
        </div>
    </td>
</tr>
