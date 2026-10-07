@props(['release', 'onlyWhenIncomplete' => false, 'showRepair' => true])

@php
    use App\Support\ReleaseCompletion;

    $completion = is_array($release) ? ($release['completion'] ?? null) : ($release->completion ?? null);

    $isMeasured = ReleaseCompletion::isMeasured($completion);
    $percent = ReleaseCompletion::percent($completion);
    $isIncomplete = ReleaseCompletion::isIncomplete($completion);
    $stillRepairing = $isIncomplete && ReleaseCompletion::stillRepairing($release);

    $variant = match (true) {
        $percent >= 100 => 'success',
        $percent >= 95 => 'warning',
        default => 'danger',
    };
@endphp

@if($isMeasured && (! filter_var($onlyWhenIncomplete, FILTER_VALIDATE_BOOL) || $isIncomplete))
    <x-chip :variant="$variant" :icon="$percent === 100 ? 'fas fa-check' : null" class="completion-badge"
            title="{{ $percent }}% of this release's articles were seen by the indexer">{{ $percent }}%</x-chip>
    @if($stillRepairing && $showRepair)
        <x-chip variant="warning" icon="fas fa-wrench" class="repair-badge"
                title="Segment repair, a header rescan or a secondary provider may still recover more of this release">{{ ReleaseCompletion::PENDING_LABEL }}</x-chip>
    @endif
@endif
