<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RecoveryPaneText
{
    public function phrase(string $token): string
    {
        return match ($token) {
            'reused_capture', 'frontier_reused' => 'already covered, nothing to download',
            'frontier_rebuilt' => 'frontier evidence rebuilt',
            'capture_handoff_pending' => 'downloaded, but saving the headers failed; retrying',
            'gap_limit_reached', 'frontier_limit_reached' => 'gave up: no further attempt could be reserved',
            'gap_unresolved', 'frontier_unresolved' => 'gave up after the final attempt',
            'expired_unresolved' => 'expired before it could run',
            'admission_pending' => 'engine stopped or recovery disabled',
            'capacity_pending' => 'no free download slot',
            'provider_backoff' => 'provider backing off',
            'retry_pending' => 'will retry',
            'ready' => 'ready, queued for publication',
            'waiting_quiet_interval' => 'waiting: changed less than 2 hours ago',
            'waiting_head_frontier' => 'waiting: headers after it are not fully captured',
            'unknown_capture_gap' => 'waiting: gap in captured headers around it',
            'unknown_left_edge' => 'waiting: no captured headers before it',
            'dirty_candidate_snapshot' => 'waiting: its headers changed during preparation',
            'awaiting_index' => 'waiting for its index download',
            'awaiting_anchors' => 'waiting for its anchor downloads',
            'obsolete' => 'superseded',
            'worker_failed', 'worker_failure', 'local_failure' => 'failed (see laravel.log)',
            default => str_replace('_', ' ', $token),
        };
    }

    /** @return 'error'|'warning'|'primary' */
    public function level(string $token): string
    {
        return match ($token) {
            'worker_failed', 'worker_failure', 'local_failure' => 'error',
            'capture_handoff_pending', 'retry_pending', 'provider_backoff', 'capacity_pending', 'admission_pending',
            'gap_limit_reached', 'frontier_limit_reached', 'gap_unresolved', 'frontier_unresolved', 'expired_unresolved' => 'warning',
            default => 'primary',
        };
    }

    /** @param array<string, int> $counters */
    public function housekeepingLine(array $counters): string
    {
        $labels = [
            'expired_headers' => 'expired %s headers', 'expired_scans' => 'deleted %s old scans',
            'expired_scan_batches' => 'deleted %s scan batches', 'compacted_incomplete_scans' => 'compacted %s incomplete scans',
            'expired_frontiers' => 'deleted %s frontier points', 'expired_frontier_conflicts' => 'deleted %s frontier conflicts',
            'expired_frontier_ranges' => 'deleted %s frontier ranges', 'compacted_attempts' => 'compacted %s attempts',
            'compacted_catalog_requests' => 'compacted %s catalog requests', 'compacted_coverage' => 'compacted %s scan summaries',
            'expired_evidence' => 'expired %s cached articles', 'expired_artifacts' => 'expired %s artifacts',
        ];
        $parts = [];
        foreach ($counters as $key => $count) {
            if ($count !== 0) {
                $parts[] = isset($labels[$key]) ? sprintf($labels[$key], number_format($count)) : number_format($count).' '.str_replace('_', ' ', $key);
            }
        }

        return 'Housekeeping: '.($parts === [] ? 'nothing to do' : implode(', ', $parts));
    }

    /** @param array{gaps_queued: int, runs_refreshed: int, bundles_refreshed: int, frontier: array<string, int>} $planning */
    public function planningLine(array $planning): string
    {
        if ($planning['gaps_queued'] === 0 && $planning['runs_refreshed'] === 0 && $planning['bundles_refreshed'] === 0 && array_sum($planning['frontier']) === 0) {
            return 'Planning: nothing to do';
        }
        $line = 'Planning: '.number_format($planning['gaps_queued']).' gap downloads queued, '.number_format($planning['runs_refreshed']).' runs refreshed, '.number_format($planning['bundles_refreshed']).' candidates refreshed';
        foreach ($planning['frontier'] as $outcome => $count) {
            if ($count !== 0) {
                $line .= ', frontier: '.number_format($count).' '.$this->phrase($outcome);
            }
        }

        return $line;
    }

    /** @param array{outcome: string, bundle_id?: ?int, purpose?: ?string, group?: ?string, first?: ?int, last?: ?int, seconds?: float} $result */
    public function workerLine(array $result): string
    {
        $outcome = $this->phrase($result['outcome']);
        $purpose = str_replace('_', ' ', $result['purpose'] ?? 'download');
        $seconds = number_format($result['seconds'] ?? 0, 1);
        if (isset($result['first'], $result['last'])) {
            return $purpose.' '.($result['group'] ?? 'unknown group').' '.number_format($result['first']).'-'.number_format($result['last']).' ('.number_format($result['last'] - $result['first'] + 1).' articles): '.$outcome.' ('.$seconds.' s)';
        }
        if (isset($result['bundle_id'])) {
            return $purpose.' for candidate '.$result['bundle_id'].': '.$outcome.' ('.$seconds.' s)';
        }

        return $outcome;
    }

    /** @param 'header'|'primary'|'warning'|'error' $level */
    public function say(string $line, string $level = 'primary'): void
    {
        cli()->{$level}(htmlspecialchars($line, ENT_NOQUOTES));
    }

    public function title(string $stage, ?int $workers = null): string
    {
        return 'Recovery '.$stage.' at '.now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s T').($workers === null ? '' : ', '.$workers.' workers');
    }

    public function candidateLine(int $bundleId, int $revision, string $result, float $seconds): string
    {
        $bundle = DB::table('obfuscation_recovery_bundles as b')->leftJoin('usenet_groups as g', 'g.id', '=', 'b.groups_id')
            ->where('b.id', $bundleId)->first(['b.start_ms', 'b.publication_id', 'g.name']);
        $group = $bundle->name ?? 'unknown group';
        if ($result === 'published' && $bundle?->publication_id !== null) {
            $release = DB::table('obfuscation_recovery_publications as p')->join('releases as r', 'r.id', '=', 'p.releases_id')
                ->where('p.id', $bundle->publication_id)->first(['p.releases_id', 'r.searchname']);
            if ($release !== null && $release->searchname !== null && $bundle->name !== null) {
                return 'Published release '.$release->releases_id.' "'.mb_substr($release->searchname, 0, 70).'" (candidate '.$bundleId.', '.$group.')';
            }
        }
        $posted = $bundle?->start_ms === null ? 'unknown' : Carbon::createFromTimestampMs((int) $bundle->start_ms, 'UTC')->timezone(config('app.timezone'))->format('Y-m-d H:i');

        return 'Candidate '.$bundleId.' in '.$group.', posted '.$posted.', revision '.$revision.': '.$this->phrase($result).' ('.number_format($seconds, 1).' s)';
    }

    /** @return list<string> */
    public function backlogLines(): array
    {
        $rows = DB::table('obfuscation_recovery_bundles')->where('kind', 'posting')->selectRaw('state, reason, COUNT(*) AS total')->groupBy('state', 'reason')->get();
        $totals = ['collecting' => 0, 'ready' => 0, 'publishing' => 0];
        $reasons = [];
        foreach ($rows as $row) {
            $totals[$row->state] = ($totals[$row->state] ?? 0) + (int) $row->total;
            if ($row->state === 'collecting') {
                $reasons[$row->reason ?? 'unknown'] = (int) $row->total;
            }
        }
        arsort($reasons);
        $parts = [];
        foreach (array_slice($reasons, 0, 3, true) as $reason => $count) {
            $parts[] = number_format($count).' '.$this->phrase($reason);
        }
        if (count($reasons) > 3) {
            $parts[] = '+'.(count($reasons) - 3).' more';
        }
        $queue = DB::table('obfuscation_recovery_work')->whereIn('status', ['pending', 'claimed'])
            ->selectRaw('stage, purpose, COUNT(*) AS total')->groupBy('stage', 'purpose')->orderByDesc('total')->orderBy('stage')->orderBy('purpose')->get();
        $published = DB::table('obfuscation_recovery_publications')->where('state', 'published')->where('created_at', '>=', now()->subHours(24))->count();

        return ['Backlog: '.number_format($totals['collecting']).' collecting'.($parts === [] ? '' : ' ('.implode(', ', $parts).')').', '.number_format($totals['ready']).' ready, '.number_format($totals['publishing']).' publishing, '.number_format($published).' published in 24 h',
            'Queue: '.($queue->isEmpty() ? 'empty' : $queue->map(fn (object $row): string => number_format((int) $row->total).' '.str_replace('_', ' ', $row->purpose))->implode(', '))];
    }

    public function queueLine(): string
    {
        $queue = DB::table('obfuscation_recovery_work')->where('stage', 'download')->whereIn('status', ['pending', 'claimed'])
            ->selectRaw('purpose, COUNT(*) AS total')->groupBy('purpose')->orderByDesc('total')->orderBy('purpose')->get();
        $occupied = DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count();

        return 'Queue: '.($queue->isEmpty() ? 'empty' : $queue->map(fn (object $row): string => number_format((int) $row->total).' '.str_replace('_', ' ', $row->purpose))->implode(', ')).', slots '.number_format($occupied).'/'.RecoveryConfig::fromSettings()->threads.' busy';
    }

    /** @param array<string, mixed> $data */
    public function observe(RecoveryStage $stage, string $event, array $data): void
    {
        switch ($event) {
            case 'housekeeping':
                $this->say($this->housekeepingLine($data));
                break;
            case 'planning':
                $this->say($this->planningLine($data));
                break;
            case 'bootstrap':
                $this->say('Bootstrap: publication '.$data['publication_id'].' '.$this->phrase($data['result']));
                break;
            case 'naming':
                $this->say('Naming: '.$this->phrase($data['result']));
                break;
            case 'claim':
                $this->say($this->candidateLine($data['bundle_id'], $data['revision'], $data['result'], $data['seconds']), $this->level($data['result']));
                break;
            case 'engine_stopped':
                $this->say('Engine stopped.', 'warning');
                break;
            case 'done':
                if ($stage === RecoveryStage::Discover) {
                    foreach ($this->backlogLines() as $line) {
                        $this->say($line);
                    }
                }
                $this->say('Done: '.number_format($data['claims']).' claims in '.number_format($data['seconds'], 1).' s');
                break;
        }
    }
}
