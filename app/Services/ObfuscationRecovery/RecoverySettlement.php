<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RecoverySettlement
{
    public function assess(string $epoch, int $group, int $generation, int $firstArticle, int $lastArticle,
        string $firstPostdate, string $lastPostdate, string $changedAt, bool $retainedPlan = false, ?object $candidate = null): string
    {
        if ($firstArticle < 1 || $lastArticle < $firstArticle || $firstPostdate > $lastPostdate
            || RecoveryCoverage::sourceDate($firstPostdate.' +0000') !== $firstPostdate
            || RecoveryCoverage::sourceDate($lastPostdate.' +0000') !== $lastPostdate
            || $lastPostdate > now('UTC')->addDay()->format('Y-m-d H:i:s')) {
            return 'invalid_candidate_envelope';
        }
        if (Carbon::parse($changedAt, 'UTC')->addMinutes(120)->isFuture()) {
            return 'waiting_quiet_interval';
        }
        $leftDate = Carbon::parse($firstPostdate, 'UTC')->subMinutes(120)->format('Y-m-d H:i:s');
        $rightDate = Carbon::parse($lastPostdate, 'UTC')->addMinutes(120)->format('Y-m-d H:i:s');
        $scopeDigest = RecoveryPositiveCoverage::scope($epoch, $group, $generation);
        $head = RecoveryFrontiers::witnesses(DB::connection(), $scopeDigest);
        $left = (clone $head)->where('article_number', '<', $firstArticle)->where('postdate', '<=', $leftDate)
            ->max('article_number');
        $right = (clone $head)->where('article_number', '>', $lastArticle)->where('postdate', '>=', $rightDate)
            ->min('article_number');
        $start = $left === null ? $firstArticle : (int) $left;
        $end = $right === null ? $lastArticle : (int) $right;
        $islands = [];
        $ranges = DB::table('obfuscation_recovery_coverage')->where('source_epoch', $epoch)->where('groups_id', $group)
            ->where('capture_generation', $generation)->where('kind', $retainedPlan ? 'retained' : 'captured')
            ->where('first_article', '<=', $end)->where('last_article', '>=', $start)
            ->orderBy('first_article')->orderBy('last_article')->limit(10001)->get();
        if ($ranges->count() > 10000) {
            return 'coverage_context_limit';
        }
        foreach ($ranges as $range) {
            $first = max($start, (int) $range->first_article);
            $last = min($end, (int) $range->last_article);
            $index = count($islands) - 1;
            if ($index >= 0 && $first <= $islands[$index][1] + 1) {
                $islands[$index][1] = max($islands[$index][1], $last);
            } else {
                $islands[] = [$first, $last];
            }
        }
        $containing = null;
        foreach ($islands as $island) {
            if ($island[0] <= $firstArticle && $island[1] >= $lastArticle) {
                $containing = $island;
                break;
            }
        }
        if ($containing === null) {
            return 'unknown_capture_gap';
        }
        $conflicts = DB::table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scopeDigest);
        $legacy = RecoveryFrontierConflicts::overlapping(DB::connection(), $scopeDigest, $containing[0], $containing[1], ['unknown', 'ordering'])->exists();
        $contradictions = (clone $conflicts)->where('kind', 'contradiction')->whereBetween('first_article', [$firstArticle, $lastArticle]);
        if ($candidate === null) {
            $contradiction = $contradictions->whereIn('first_article', [$firstArticle, $lastArticle])->exists();
        } elseif (! $contradictions->exists()) {
            $contradiction = false;
        } elseif ($retainedPlan && ! (new RecoveryFrontierMembers)->complete(DB::connection(), $candidate)) {
            return 'frontier_member_evidence_required';
        } else {
            $members = $retainedPlan ? DB::table('obfuscation_recovery_frontier_members')->where('bundle_id', $candidate->id)->where('revision', $candidate->revision)
                : (new RecoveryFrontierMembers)->raw(DB::connection(), $candidate);
            $table = $retainedPlan ? 'obfuscation_recovery_frontier_members' : 'obfuscation_recovery_headers';
            $contradiction = $members->whereExists(fn ($query) => $query->selectRaw('1')->from('obfuscation_recovery_frontier_conflicts as conflict')
                ->where('conflict.scope_digest', $scopeDigest)->where('conflict.kind', 'contradiction')->whereColumn('conflict.first_article', $table.'.article_number'))->exists();
        }
        if ($contradiction) {
            return 'conflicting_posting_frontier';
        }
        if ($legacy) {
            return 'frontier_rebuild_required';
        }
        $usable = RecoveryFrontiers::witnesses(DB::connection(), $scopeDigest, false);
        if (($left === null && (clone $usable)->where('article_number', '<', $firstArticle)->where('postdate', '<=', $leftDate)->exists())
            || ($right === null && (clone $usable)->where('article_number', '>', $lastArticle)->where('postdate', '>=', $rightDate)->exists())) {
            return 'conflicting_boundary_witness';
        }

        return match (true) {
            $left === null || $containing[0] > $start => 'unknown_left_edge',
            $right === null || $containing[1] < $end => 'waiting_head_frontier',
            default => 'ready',
        };
    }
}
