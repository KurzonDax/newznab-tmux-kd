@php
    /**
     * The blacklist sweep's status in a poster list's pager line (docs/proposals/generic-release-lists/SPEC.md 5.8,
     * issue #1032 corrections 1 and 5): the slot is always rendered so nothing moves; the status names the run this
     * administrator started from this poster's page and only that run. Running describes an attempt; a finished run
     * reports its actual removals, and how many releases of the exact poster remain when some do; a failed run
     * reports only the known removals; a run whose metadata is gone confirms nothing. While running, the span polls
     * that run and reloads the list when it ends (posterSweepStatus).
     *
     * @var array{state: string, rule: int, run: string, removed: int, remaining: ?int, exitCode: ?int}|null $sweep
     */
    $sweep ??= null;
    $count = static fn (int $number): string => number_format($number).' '.\Illuminate\Support\Str::plural('release', $number);
    $were = static fn (int $number): string => $number === 1 ? 'was' : 'were';
@endphp
<span class="tv-sweep-slot">
    @if($sweep !== null)
        @php($state = $sweep['state'])
        <span class="tv-sweep" role="status" data-sweep-status="{{ $state }}" data-run="{{ $sweep['run'] }}" x-data="posterSweepStatus"
              data-status-url="{{ route('admin.binaryblacklist-sweep.status', ['run' => $sweep['run']]) }}" data-running="{{ $state === \App\Services\PosterIdentityBrowserContext::RUNNING ? '1' : '0' }}">
            <i class="fas fa-ban" aria-hidden="true"></i>
            @switch($state)
                @case(\App\Services\PosterIdentityBrowserContext::RUNNING)
                    <span><b>Rule #{{ $sweep['rule'] }} added · sweep running.</b> Removing this poster’s releases…</span>
                    @break
                @case(\App\Services\PosterIdentityBrowserContext::COMPLETE)
                    <span><b>Rule #{{ $sweep['rule'] }} added · sweep finished.</b> {{ $count($sweep['removed']) }} by this poster {{ $were($sweep['removed']) }} removed.</span>
                    @break
                @case(\App\Services\PosterIdentityBrowserContext::PARTIAL)
                    <span><b>Rule #{{ $sweep['rule'] }} added · sweep finished.</b> {{ $count($sweep['removed']) }} by this poster {{ $were($sweep['removed']) }} removed · {{ $count((int) $sweep['remaining']) }} {{ $sweep['remaining'] === 1 ? 'remains' : 'remain' }}.</span>
                    @break
                @case(\App\Services\PosterIdentityBrowserContext::FAILED)
                    <span><b>Rule #{{ $sweep['rule'] }} added · sweep failed.</b> {{ $count($sweep['removed']) }} by this poster {{ $were($sweep['removed']) }} removed before it stopped (exit {{ $sweep['exitCode'] }}).</span>
                    @break
                @default
                    <span><b>Rule #{{ $sweep['rule'] }} added · sweep result unavailable.</b> Its run could not be found, so nothing is confirmed.</span>
            @endswitch
        </span>
    @endif
</span>
