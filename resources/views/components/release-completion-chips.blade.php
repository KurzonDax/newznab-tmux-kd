@props(['release', 'onlyWhenIncomplete' => false, 'showRepair' => true])

@php
    use App\Support\ReleaseCompletion;

    $completion = is_array($release) ? ($release['completion'] ?? null) : ($release->completion ?? null);
    $repairOutcome = is_array($release) ? ($release['repair_outcome'] ?? null) : ($release->repair_outcome ?? null);
    $rescanOutcome = is_array($release) ? ($release['rescan_outcome'] ?? null) : ($release->rescan_outcome ?? null);

    $isMeasured = ReleaseCompletion::isMeasured($completion);
    $percent = ReleaseCompletion::percent($completion);
    $isIncomplete = ReleaseCompletion::isIncomplete($completion);
    $repairLabel = ReleaseCompletion::repairLabel($repairOutcome, $rescanOutcome);
    $repairIsComplete = $repairLabel === ReleaseCompletion::COMPLETE_LABEL;

    $variant = match (true) {
        $percent >= 100 => 'success',
        $percent >= 95 => 'warning',
        default => 'danger',
    };
@endphp

@if($isMeasured && (! filter_var($onlyWhenIncomplete, FILTER_VALIDATE_BOOL) || $isIncomplete))
    <x-chip :variant="$variant" :icon="$percent === 100 ? 'fas fa-check' : null" class="completion-badge"
            title="{{ $percent }}% of this release's articles were seen by the indexer">{{ $percent }}%</x-chip>
    @if($isIncomplete && $showRepair)
        <x-chip :variant="$repairIsComplete ? 'neutral' : 'warning'" :icon="$repairIsComplete ? 'fas fa-flag-checkered' : 'fas fa-wrench'" class="repair-badge"
                :title="$repairIsComplete ? 'Segment repair and header rescan have both finished; this is as complete as it gets' : 'Segment repair or header rescan may still recover more of this release'">{{ $repairLabel }}</x-chip>
    @endif
@endif
