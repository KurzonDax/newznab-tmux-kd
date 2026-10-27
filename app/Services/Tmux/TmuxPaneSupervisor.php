<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\TmuxPaneRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records how each processing pane's worker cycles end and backs off panes
 * that keep failing.
 *
 * Every step of a supervised pane appends one `key=value` line to
 * `storage/tmux/panes/<role>.status`, and the cycle closes with a
 * `cycle_end` line carrying that cycle's sleep. Before the monitor decides
 * what to do with a role it counts the newest finished cycle once, keeps the
 * consecutive-failure count and backoff in `storage/tmux/pane-supervision.json`
 * (it is that file's only writer) and empties the status file.
 */
final class TmuxPaneSupervisor
{
    /** Set for each step; the worker writes `failed/total` children there. */
    public const string CHILDREN_ENV = 'NNTMUX_PANE_CHILDREN';

    /** The supervised roles and the names the monitor display gives them. */
    public const array ROLES = [
        'binaries' => 'Binaries',
        'backfill' => 'Backfill',
        'releases' => 'Releases',
        'fix_names' => 'Fix Names',
        'remove_crap' => 'Remove Crap',
        'post_additional' => 'PP Additional',
        'post_tv' => 'PP TV/Anime',
        'post_movies' => 'PP Movies',
        'post_metadata' => 'PP Metadata',
        'recovery_local' => 'Recovery Local',
        'recovery_download' => 'Recovery DL',
    ];

    /** Exit codes `timeout` reports for a step it cut off: TERM, then KILL. */
    private const array TIMEOUT_EXITS = [124, 137];

    public function statusFile(TmuxPaneRole $role): string
    {
        return $this->directory().'/'.$role->value.'.status';
    }

    public function childrenFile(TmuxPaneRole $role): string
    {
        return $this->directory().'/'.$role->value.'.children';
    }

    public function ensureDirectory(): void
    {
        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0755, true);
        }
    }

    /**
     * Forget every role's history, including cycles a pane finished before
     * the restart; the monitor does this when it starts.
     */
    public function reset(): void
    {
        $files = [
            $this->stateFile(),
            ...(glob($this->directory().'/*.status') ?: []),
            ...(glob($this->directory().'/*.children') ?: []),
        ];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Count the role's newest finished cycle, once.
     */
    public function observe(TmuxPaneRole $role): void
    {
        $cycle = $this->finishedCycle($role);
        if ($cycle === null) {
            return;
        }

        $state = $this->load();
        $entry = $state[$role->value] ?? [];
        if ($cycle['cycle'] <= (int) ($entry['last_counted_cycle'] ?? 0)) {
            return;
        }

        $state[$role->value] = $this->count($role, $entry, $cycle);
        $this->save($state);
        file_put_contents($this->statusFile($role), '');
    }

    /**
     * Drop a role's history; a pane disabled in settings starts clean.
     */
    public function forget(TmuxPaneRole $role): void
    {
        $state = $this->load();
        if (! array_key_exists($role->value, $state)) {
            return;
        }

        unset($state[$role->value]);
        $this->save($state);
    }

    /**
     * The park message for a role still waiting out its backoff, or null
     * when it may launch.
     */
    public function backoffMessage(TmuxPaneRole $role, string $taskName): ?string
    {
        $entry = $this->load()[$role->value] ?? [];
        $nextStart = $entry['next_start'] ?? null;
        if (! is_int($nextStart) || now()->getTimestamp() >= $nextStart) {
            return null;
        }

        return sprintf(
            '%s is backing off after %d failed runs (last: %s exit %d); next try %s',
            $taskName,
            (int) ($entry['failures'] ?? 0),
            (string) ($entry['last']['step'] ?? 'unknown'),
            (int) ($entry['last']['exit'] ?? 0),
            Carbon::createFromTimestamp($nextStart, config('app.timezone'))->format('H:i:s'),
        );
    }

    /**
     * One entry per supervised role, keyed by role, for the monitor display;
     * `last` is null until the role has a counted cycle.
     *
     * @return array<string, array{label: string, last: array<string, mixed>|null, failures: int}>
     */
    public function summaries(): array
    {
        $state = $this->load();
        $summaries = [];
        foreach (self::ROLES as $role => $label) {
            $last = $state[$role]['last'] ?? null;
            $summaries[$role] = [
                'label' => $label,
                'last' => is_array($last) ? $last : null,
                'failures' => (int) ($state[$role]['failures'] ?? 0),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{cycle: int, end: int, sleep: int, steps: list<array<string, string>>}  $cycle
     * @return array<string, mixed>
     */
    private function count(TmuxPaneRole $role, array $entry, array $cycle): array
    {
        $failed = null;
        foreach ($cycle['steps'] as $step) {
            $exit = (int) $step['exit'];
            $timedOut = ($step['bounded'] ?? '') === '1' && in_array($exit, self::TIMEOUT_EXITS, true);
            if ($exit !== 0 && ! $timedOut) {
                $failed = $step;
                break;
            }
        }

        $reported = $failed ?? ($cycle['steps'] === [] ? null : $cycle['steps'][array_key_last($cycle['steps'])]);
        $duration = max(0, $cycle['end'] - $cycle['cycle']);
        $entry['last_counted_cycle'] = $cycle['cycle'];
        $entry['last'] = [
            'step' => $reported['step'] ?? null,
            'exit' => $failed === null ? 0 : (int) $failed['exit'],
            'duration' => $duration,
            'children_failed' => $this->childrenFailed($cycle['steps']),
        ];

        $threshold = max(1, (int) config('tmux.monitor.failures_before_backoff', 3));
        $failures = (int) ($entry['failures'] ?? 0);

        if ($failed === null) {
            if ($failures >= $threshold) {
                Log::info("Tmux pane {$role->value} recovered after {$failures} failed runs");
            }

            return ['failures' => 0, 'wait' => 0, 'next_start' => null] + $entry;
        }

        $failures++;
        Log::warning("Tmux pane {$role->value} failed: step {$failed['step']} exited {$failed['exit']} after {$duration}s", [
            'role' => $role->value,
            'step' => $failed['step'],
            'exit' => (int) $failed['exit'],
            'duration' => $duration,
        ]);

        $entry['failures'] = $failures;
        if ($failures < $threshold) {
            return $entry;
        }

        // The first wait is the pane's own sleep, floored at the monitor tick so
        // a 0-second timer still pauses; each further failure doubles it.
        $previousWait = (int) ($entry['wait'] ?? 0);
        $wait = $previousWait > 0
            ? $previousWait * 2
            : max($cycle['sleep'], (int) config('tmux.monitor.delay', 10));
        $wait = min($wait, max(1, (int) config('tmux.monitor.max_backoff_seconds', 1800)));
        $entry['wait'] = $wait;
        $entry['next_start'] = $cycle['end'] + $cycle['sleep'] + $wait;

        if ($previousWait === 0) {
            Log::warning("Tmux pane {$role->value} is backing off for {$wait}s after {$failures} failed runs");
        }

        return $entry;
    }

    /**
     * The newest cycle in the role's status file that has a `cycle_end`
     * line. Older cycles without one (killed mid-step) are dropped, and a
     * cycle still running has no `cycle_end` yet.
     *
     * @return array{cycle: int, end: int, sleep: int, steps: list<array<string, string>>}|null
     */
    private function finishedCycle(TmuxPaneRole $role): ?array
    {
        $file = $this->statusFile($role);
        if (! is_file($file)) {
            return null;
        }

        $steps = [];
        $ends = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = $this->parse($line);
            if ($fields === null) {
                continue;
            }

            $cycle = (int) $fields['cycle'];
            if (isset($fields['cycle_end'])) {
                $ends[$cycle] = $fields;
            } else {
                $steps[$cycle][] = $fields;
            }
        }

        if ($ends === []) {
            return null;
        }

        $cycle = max(array_keys($ends));

        return [
            'cycle' => $cycle,
            'end' => (int) $ends[$cycle]['end'],
            'sleep' => (int) $ends[$cycle]['sleep'],
            'steps' => $steps[$cycle] ?? [],
        ];
    }

    /**
     * Split one status line into its fields. A step label may hold spaces, so
     * a value runs until the next ` key=`.
     *
     * @return array<string, string>|null
     */
    private function parse(string $line): ?array
    {
        if (preg_match_all('/(\w+)=(.*?)(?=\s+\w+=|\s*$)/', trim($line), $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $fields = [];
        foreach ($matches as [, $key, $value]) {
            $fields[$key] = $value;
        }

        $required = isset($fields['cycle_end']) ? ['cycle', 'end', 'sleep'] : ['cycle', 'exit', 'start', 'end'];
        foreach ($required as $key) {
            if (! isset($fields[$key]) || ! ctype_digit($fields[$key])) {
                return null;
            }
        }

        if (! isset($fields['cycle_end']) && ! isset($fields['step'])) {
            return null;
        }

        return $fields;
    }

    /**
     * Sum the steps' `children_failed=N/M` fields, or null when no step ran
     * pool children.
     *
     * @param  list<array<string, string>>  $steps
     */
    private function childrenFailed(array $steps): ?string
    {
        $failed = 0;
        $total = 0;
        $seen = false;
        foreach ($steps as $step) {
            if (preg_match('/^(\d+)\/(\d+)$/', $step['children_failed'] ?? '', $match) === 1) {
                $failed += (int) $match[1];
                $total += (int) $match[2];
                $seen = true;
            }
        }

        return $seen ? "{$failed}/{$total}" : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (! is_file($this->stateFile())) {
            return [];
        }

        $state = json_decode((string) file_get_contents($this->stateFile()), true);

        return is_array($state) ? array_filter($state, 'is_array') : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $state
     */
    private function save(array $state): void
    {
        $directory = dirname($this->stateFile());
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->stateFile(), json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function directory(): string
    {
        return storage_path('tmux/panes');
    }

    private function stateFile(): string
    {
        return storage_path('tmux/pane-supervision.json');
    }
}
