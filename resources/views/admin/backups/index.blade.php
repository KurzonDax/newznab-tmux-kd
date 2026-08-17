@extends('layouts.admin')

@section('content')
    <div class="space-y-6">
        <x-page-header title="Database Backups" subtitle="Schedule verified online database dumps and copy Backup sets to separate storage." />

        @if(session('success'))
            <x-panel class="border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-950/30 text-green-800 dark:text-green-200">
                {{ session('success') }}
            </x-panel>
        @endif

        @if(session('error'))
            <x-panel class="border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-200">
                {{ session('error') }}
            </x-panel>
        @endif

        @if($errors->any())
            <x-panel class="border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-950/30">
                <p class="font-semibold text-red-800 dark:text-red-200">Unable to save backup settings</p>
                <ul class="mt-2 list-disc pl-5 text-sm text-red-700 dark:text-red-300">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-panel>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-panel>
                <p class="text-sm text-gray-600 dark:text-gray-400">Engine</p>
                <p class="mt-1 font-semibold {{ $supported ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">
                    {{ $supported ? 'Supported' : 'Unsupported' }} ({{ $databaseDriver }})
                </p>
            </x-panel>
            <x-panel>
                <p class="text-sm text-gray-600 dark:text-gray-400">Next scheduled run</p>
                <p class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $nextRun?->format('M j, Y g:i A T') ?? 'Disabled' }}</p>
            </x-panel>
            <x-panel>
                <p class="text-sm text-gray-600 dark:text-gray-400">Last Full</p>
                <p class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $lastFull?->finished_at?->format('M j, Y g:i A T') ?? 'Never' }}</p>
                @if($lastFull)<p class="text-sm text-gray-600 dark:text-gray-400">{{ ucfirst($lastFull->status) }}@if($lastFull->bytes) · {{ number_format($lastFull->bytes / 1048576, 1) }} MiB @endif</p>@endif
            </x-panel>
            <x-panel>
                <p class="text-sm text-gray-600 dark:text-gray-400">Last Daily</p>
                <p class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $lastDaily?->finished_at?->format('M j, Y g:i A T') ?? 'Never' }}</p>
                @if($lastDaily)<p class="text-sm text-gray-600 dark:text-gray-400">{{ ucfirst($lastDaily->status) }}@if($lastDaily->bytes) · {{ number_format($lastDaily->bytes / 1048576, 1) }} MiB @endif</p>@endif
            </x-panel>
        </div>

        <x-panel>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Run now</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Requests are consumed by the scheduler within one minute; no queue worker is required.</p>
                </div>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('admin.backups.run', ['kind' => 'full']) }}">@csrf<x-button type="submit" variant="primary" icon="fas fa-database">Run Full now</x-button></form>
                    <form method="POST" action="{{ route('admin.backups.run', ['kind' => 'daily']) }}">@csrf<x-button type="submit" variant="secondary" icon="fas fa-calendar-day">Run Daily now</x-button></form>
                </div>
            </div>
        </x-panel>

        <form method="POST" action="{{ route('admin.backups.update') }}" class="space-y-6">
            @csrf
            <x-panel>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Schedule and local retention</h2>
                <div class="grid gap-5 md:grid-cols-2">
                    <x-form.group label="Backups enabled" for="backup_enabled"><x-select id="backup_enabled" name="backup_enabled"><option value="0" @selected(old('backup_enabled', $site['backup_enabled'] ?? 0) == 0)>No</option><option value="1" @selected(old('backup_enabled', $site['backup_enabled'] ?? 0) == 1)>Yes</option></x-select></x-form.group>
                    <x-form.group label="Full backup day" for="backup_full_dow"><x-select id="backup_full_dow" name="backup_full_dow">@foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day => $label)<option value="{{ $day }}" @selected(old('backup_full_dow', $site['backup_full_dow'] ?? 0) == $day)>{{ $label }}</option>@endforeach</x-select></x-form.group>
                    <x-form.group label="Full backup time" for="backup_full_time"><x-input id="backup_full_time" name="backup_full_time" type="time" value="{{ old('backup_full_time', $site['backup_full_time'] ?? '02:00') }}" required /></x-form.group>
                    <x-form.group label="Daily backup time" for="backup_daily_time"><x-input id="backup_daily_time" name="backup_daily_time" type="time" value="{{ old('backup_daily_time', $site['backup_daily_time'] ?? '02:00') }}" required /></x-form.group>
                    <x-form.group label="Backup location" for="backup_location" help="Absolute local path; never place it under the public web root."><x-input id="backup_location" name="backup_location" value="{{ old('backup_location', $site['backup_location'] ?? '') }}" required />@error('backup_location')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</x-form.group>
                    <x-form.group label="Full backups to keep" for="backup_keep_fulls"><x-input id="backup_keep_fulls" name="backup_keep_fulls" type="number" min="1" max="100" value="{{ old('backup_keep_fulls', $site['backup_keep_fulls'] ?? 4) }}" required /></x-form.group>
                    <x-form.group label="Backup pause" for="backup_pause_tmux"><x-select id="backup_pause_tmux" name="backup_pause_tmux"><option value="1" @selected(old('backup_pause_tmux', $site['backup_pause_tmux'] ?? 1) == 1)>Pause tmux</option><option value="0" @selected(old('backup_pause_tmux', $site['backup_pause_tmux'] ?? 1) == 0)>Leave tmux running</option></x-select></x-form.group>
                    <x-form.group label="Working tables in Full" for="backup_incl_working"><x-select id="backup_incl_working" name="backup_incl_working"><option value="1" @selected(old('backup_incl_working', $site['backup_incl_working'] ?? 1) == 1)>Include</option><option value="0" @selected(old('backup_incl_working', $site['backup_incl_working'] ?? 1) == 0)>Exclude</option></x-select></x-form.group>
                    <x-form.group label="Dump binary override" for="backup_dump_binary" help="Optional absolute path to mariadb-dump or mysqldump."><x-input id="backup_dump_binary" name="backup_dump_binary" value="{{ old('backup_dump_binary', $site['backup_dump_binary'] ?? '') }}" /></x-form.group>
                </div>
            </x-panel>

            <x-panel>
                <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Off-site copy</h2>
                <div class="grid gap-5 md:grid-cols-2">
                    <x-form.group label="Off-site destination" for="backup_offsite_path" help="Absolute path on external or network storage."><x-input id="backup_offsite_path" name="backup_offsite_path" value="{{ old('backup_offsite_path', $site['backup_offsite_path'] ?? '') }}" />@error('backup_offsite_path')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</x-form.group>
                    <x-form.group label="Copy after each backup" for="backup_offsite_after"><x-select id="backup_offsite_after" name="backup_offsite_after"><option value="0" @selected(old('backup_offsite_after', $site['backup_offsite_after'] ?? 0) == 0)>No</option><option value="1" @selected(old('backup_offsite_after', $site['backup_offsite_after'] ?? 0) == 1)>Yes</option></x-select></x-form.group>
                    <x-form.group label="Off-site sets to keep" for="backup_offsite_keep" help="Zero keeps every copied set."><x-input id="backup_offsite_keep" name="backup_offsite_keep" type="number" min="0" max="1000" value="{{ old('backup_offsite_keep', $site['backup_offsite_keep'] ?? 0) }}" /></x-form.group>
                </div>
            </x-panel>

            <div class="flex justify-end"><x-button type="submit" variant="primary" icon="fas fa-save">Save backup settings</x-button></div>
        </form>

        <x-panel>
            <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Backup sets on disk</h2>
            @if($locationError)<p class="text-red-700 dark:text-red-300">{{ $locationError }}</p>
            @elseif($sets === [])<p class="text-gray-600 dark:text-gray-400">No Backup sets found.</p>
            @else
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"><thead><tr class="text-left text-sm text-gray-600 dark:text-gray-400"><th class="py-2 pr-4">Set</th><th class="py-2 pr-4">Kinds</th><th class="py-2 pr-4">Files</th><th class="py-2 pr-4">Newest</th><th class="py-2 pr-4">Size</th><th class="py-2 pr-4">Verified</th><th class="py-2 pr-4">Off-site</th></tr></thead><tbody class="divide-y divide-gray-200 dark:divide-gray-700">@foreach($sets as $set)<tr class="text-sm text-gray-800 dark:text-gray-200"><td class="py-3 pr-4 font-mono">{{ $set['set_id'] }}</td><td class="py-3 pr-4">{{ collect($set['files'])->pluck('manifest.kind')->unique()->map(fn ($kind) => ucfirst($kind))->join(', ') }}</td><td class="py-3 pr-4">{{ count($set['files']) }}</td><td class="py-3 pr-4">{{ \Illuminate\Support\Carbon::parse($set['newest_at'])->format('M j, Y g:i A T') }}</td><td class="py-3 pr-4">{{ number_format($set['bytes'] / 1048576, 1) }} MiB</td><td class="py-3 pr-4">{{ $set['verified'] ? 'Yes' : 'No' }}</td><td class="py-3 pr-4">{{ $set['offsite_status'] }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </x-panel>
    </div>
@endsection
