@if($recovery['available'] ?? false)
<x-admin.card>
    <h2 class="text-lg font-semibold">Recovery processing</h2>
    <p class="text-sm text-muted mt-2">{{ $recovery['enabled'] ? 'Enabled' : 'Disabled' }} · {{ $recovery['occupied_slots'] }} / {{ $recovery['worker_limit'] }} thread slots occupied · {{ $recovery['retention_hours'] }} hours raw retention</p>
    <p class="text-sm text-muted mt-2">{{ $recovery['account_allocation'] }}</p>
    <p class="text-sm mt-2">{{ number_format($recovery['opens_per_second'], 2) }} connections/s · {{ number_format($recovery['observed_bytes_per_second']) }} observed bytes/s · {{ number_format($recovery['accounted_bytes_per_second']) }} accounted bytes/s (last minute)</p>
    <p class="text-sm mt-2">{{ $recovery['active_connections'] }} active connections · {{ $recovery['arrivals_last_minute'] }} new bundles and {{ $recovery['completions_last_minute'] }} completed work items in the last minute</p>
    <p class="text-sm mt-2">Oldest pending age: {{ $recovery['oldest_pending_age_seconds'] === null ? 'no pending work' : number_format($recovery['oldest_pending_age_seconds']).' seconds' }} · Retention runway: {{ $recovery['retention_runway_seconds'] === null ? 'no captured headers' : number_format($recovery['retention_runway_seconds']).' seconds' }}</p>
    <p class="text-sm text-muted mt-2">Expiry and backlog indicate capacity loss. Recovery yield requires complete coverage; publication and naming outcomes are shown separately.</p>
    <p class="text-sm text-muted mt-2">{{ number_format($recovery['storage']['raw_headers']) }} retained headers · {{ number_format($recovery['storage']['artifacts']) }} stored artifacts ({{ number_format($recovery['storage']['artifact_bytes']) }} bytes) · {{ number_format($recovery['storage']['cached_articles']) }} cached articles · {{ number_format($recovery['storage']['compacted_attempts']) }} compacted attempts</p>
    <div class="grid gap-4 md:grid-cols-2 mt-4">
        @foreach(['queued_work' => 'Stage queues', 'publications' => 'Publication outcomes', 'naming' => 'Naming outcomes', 'traffic' => 'Lifetime traffic', 'catalog' => 'Catalog requests and cache reads', 'inventories' => 'Inventory sizes and outcomes', 'survivors' => 'Duplicate survivor membership'] as $key => $label)
            <div class="surface-panel p-3 rounded-lg">
                <h3 class="font-semibold">{{ $label }}</h3>
                @forelse($recovery[$key] as $row)
                    <p class="text-sm mt-2">{{ collect($row)->map(fn ($value, $field) => str_replace('_', ' ', $field).': '.($value ?? 'unknown'))->implode(' · ') }}</p>
                @empty
                    <p class="text-sm text-muted mt-2">No observations yet.</p>
                @endforelse
            </div>
        @endforeach
    </div>
    <details class="mt-4">
        <summary class="cursor-pointer text-primary-600 dark:text-primary-400">Group selection, coverage and exclusions</summary>
        @foreach(['groups' => 'Group selection', 'headers' => 'Captured headers', 'coverage' => 'Independent coverage', 'group_bundles' => 'Posting bundles and verified manifests', 'group_files' => 'File candidates and inspection outcomes', 'gap_retries' => 'Gap retry outcomes', 'reasons' => 'Exclusions and expiry', 'work_outcomes' => 'Work outcomes', 'claims' => 'Active claims (first 100)', 'allowances' => 'Candidate allowances (first 100)'] as $key => $label)
            <h3 class="font-semibold mt-3">{{ $label }}</h3>
            @forelse($recovery[$key] as $row)
                <p class="text-sm mt-1">{{ collect($row)->except(['series_digest', 'source_epoch'])->map(fn ($value, $field) => str_replace('_', ' ', $field).': '.($value ?? 'unknown'))->implode(' · ') }}</p>
            @empty
                <p class="text-sm text-muted mt-1">No observations yet.</p>
            @endforelse
        @endforeach
        @if($recovery['next_group_cursor'] !== null)
            <x-button-link class="mt-3" :href="route('admin.status.index', ['recovery_after_group' => $recovery['next_group_cursor']])" variant="secondary">Next groups</x-button-link>
        @endif
        @if($recovery['next_bundle_cursor'] !== null)
            <x-button-link class="mt-3" :href="route('admin.status.index', ['recovery_after_group' => $recovery['group_cursor'], 'recovery_after_bundle' => $recovery['next_bundle_cursor']])" variant="secondary">Next candidates</x-button-link>
        @endif
    </details>
    <details class="mt-4">
        <summary class="cursor-pointer text-primary-600 dark:text-primary-400">Supported downloads and operation</summary>
        <p class="text-sm mt-2">Recovered NZBs use observed article membership with bounded inventory and boundary checks. Unread payload articles have not been fully verified. Downloads require clients that assemble yEnc data by its declared offsets; automated compatibility checks cover SABnzbd and NZBGet. A failed later payload check remains possible.</p>
        <p class="text-sm mt-2">Use Release Formation settings to enable recovery and allocate threads within the provider account capacity. Choose Media, RAR or Both on Group Edit; this does not activate ordinary scanning for an inactive group. Lower threads to reduce concurrency. Adjust raw retention for staging time and candidate limits for lifetime evidence allowance; increasing a limit preserves previous spend and retry caps.</p>
        <p class="text-sm mt-2">Post-Processing settings control optional inspection downloads. Disabling them keeps cached inspection available. The global recovery switch stops new capture, discovery, downloads and publication while retaining published releases and allowing cleanup. To roll back operation, disable recovery and let bounded requests close; retain the schema and evidence while releases depend on them. Schema rollback refuses retained recovery data.</p>
    </details>
</x-admin.card>
@endif
