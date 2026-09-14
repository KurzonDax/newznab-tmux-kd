<x-release-facts :release="$release" />
@if($row->reports > 0)
    <x-chip variant="warning" icon="fas fa-flag" :href="route('details', $row->guid)" data-report-summary title="Open original report details">Reported ({{ $row->reports }})</x-chip>
@endif
@if($row->public_responses > 0)
    <x-chip variant="primary" icon="fas fa-reply" :href="route('details', $row->guid)" data-public-response title="Staff response available on release details">Response</x-chip>
@endif
