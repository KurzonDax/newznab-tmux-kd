@props(['release', 'onlyWhenIncomplete' => false])

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

    $completionChipClasses = match (true) {
        $percent >= 95 => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
        $percent >= 80 => 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200',
        default => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
    };
@endphp

@if($isMeasured && (! filter_var($onlyWhenIncomplete, FILTER_VALIDATE_BOOL) || $isIncomplete))
    <span class="completion-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $completionChipClasses }}"
          title="{{ $percent }}% of this release's articles were seen by the indexer">
        <i class="fas fa-chart-pie mr-1"></i> {{ $percent }}%
    </span>
    @if($isIncomplete)
        <span class="repair-badge inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $repairIsComplete ? 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300' : 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200' }}"
              title="{{ $repairIsComplete ? 'Segment repair and header rescan have both finished; this is as complete as it gets' : 'Segment repair or header rescan may still recover more of this release' }}">
            <i class="fas {{ $repairIsComplete ? 'fa-flag-checkered' : 'fa-wrench' }} mr-1"></i> {{ $repairLabel }}
        </span>
    @endif
@endif
