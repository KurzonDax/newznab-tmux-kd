<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
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
        ['left' => $left, 'right' => $right, 'start' => $start, 'end' => $end, 'containing' => $containing, 'limited' => $limited] =
            $this->context($epoch, $group, $generation, $firstArticle, $lastArticle, $firstPostdate, $lastPostdate, $retainedPlan);
        if ($limited) {
            return 'coverage_context_limit';
        }
        if ($containing === null) {
            return 'unknown_capture_gap';
        }
        $scopeDigest = RecoveryPositiveCoverage::scope($epoch, $group, $generation);
        $leftDate = Carbon::parse($firstPostdate, 'UTC')->subMinutes(120)->format('Y-m-d H:i:s');
        $rightDate = Carbon::parse($lastPostdate, 'UTC')->addMinutes(120)->format('Y-m-d H:i:s');
        $conflicts = DB::table('obfuscation_recovery_frontier_conflicts')->where('scope_digest', $scopeDigest);
        $legacy = (new RecoveryFrontierRequirement)->legacy(DB::connection(), $scopeDigest, $firstArticle, $lastArticle, $left, $right);
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

    /** @return array{left:?int,right:?int,start:int,end:int,containing:array{int,int}|null,limited:bool} */
    public function context(string $epoch, int $group, int $generation, int $firstArticle, int $lastArticle,
        string $firstPostdate, string $lastPostdate, bool $retainedPlan = false, ?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $scopeDigest = RecoveryPositiveCoverage::scope($epoch, $group, $generation);
        ['left' => $left, 'right' => $right] = (new RecoveryFrontierRequirement)->witnesses($connection, $scopeDigest, [
            'first_article' => $firstArticle, 'last_article' => $lastArticle, 'first_postdate' => $firstPostdate, 'last_postdate' => $lastPostdate,
        ]);
        $start = $left === null ? $firstArticle : (int) $left;
        $end = $right === null ? $lastArticle : (int) $right;
        $islands = [];
        $ranges = $connection->table('obfuscation_recovery_coverage')->where('source_epoch', $epoch)->where('groups_id', $group)
            ->where('capture_generation', $generation)->where('kind', $retainedPlan ? 'retained' : 'captured')
            ->where('first_article', '<=', $end)->where('last_article', '>=', $start)
            ->orderBy('first_article')->orderBy('last_article')->limit(10001)->get();
        if ($ranges->count() > 10000) {
            return ['left' => $left, 'right' => $right, 'start' => $start, 'end' => $end, 'containing' => null, 'limited' => true];
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

        return ['left' => $left === null ? null : (int) $left, 'right' => $right === null ? null : (int) $right,
            'start' => $start, 'end' => $end, 'containing' => $containing, 'limited' => false];
    }
}
