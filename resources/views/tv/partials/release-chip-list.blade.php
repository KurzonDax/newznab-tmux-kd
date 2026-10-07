{{-- The release chips of a chip line (SPEC section 4), in order; $chipPart names each chip's data-part, or returns null; every caller passes $clip, the row's video clip or null. --}}
@if($row->completion !== null)
    <x-chip :variant="'completion-'.$row->completion['band']" :data-part="$chipPart($row->completion['band'] === 'mid' ? 'completion chip' : 'completion chip, '.$row->completion['band'])"
            :title="$row->completion['percent'].'% of this release\'s articles were seen by the indexer. '.($row->completion['repairing'] ? 'The site may still recover more of it.' : 'The site will not try to recover more of it.')">{{ $row->completion['percent'] }}% complete{{ $row->completion['repairing'] ? ' · still repairing' : '' }}</x-chip>
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
@include('tv.partials.preview-chip', ['clip' => $clip])
@if($row->sample !== null)
    <x-chip variant="sample" action class="sample-badge" :data-guid="$row->guid" :data-release-display-name="$row->name" :data-image-url="$row->sample['thumb'] ?? ''"
            :data-full-url="$row->sample['full']" :data-part="$chipPart('Sample chip')" title="View sample image">Sample</x-chip>
@endif
