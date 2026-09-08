<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RecoverySettlement
{
    public function assess(string $epoch, int $group, int $generation, int $firstArticle, int $lastArticle,
        string $firstPostdate, string $lastPostdate, string $changedAt, bool $retainedPlan = false): string
    {
        if ($firstArticle < 1 || $lastArticle < $firstArticle || $firstPostdate > $lastPostdate) {
            return 'invalid_candidate_envelope';
        }
        if (Carbon::parse($changedAt)->addMinutes(120)->isFuture()) {
            return 'waiting_quiet_interval';
        }
        $leftDate = Carbon::parse($firstPostdate)->subMinutes(120)->format('Y-m-d H:i:s');
        $rightDate = Carbon::parse($lastPostdate)->addMinutes(120)->format('Y-m-d H:i:s');
        $scopeDigest = RecoveryPositiveCoverage::scope($epoch, $group, $generation);
        $points = DB::table('obfuscation_recovery_frontiers')->where('scope_digest', $scopeDigest);
        $head = (clone $points)->where('head_observed', true);
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
        $unknown = (clone $conflicts)->where('kind', 'unknown')->where('first_article', '<=', $containing[1])
            ->where('last_article', '>=', $containing[0])->exists();
        $reversed = (clone $conflicts)->where('kind', 'ordering')->where('first_article', '>=', $containing[0])
            ->where('last_article', '<=', $containing[1])->exists();
        $future = (clone $points)->whereBetween('article_number', $containing)
            ->where('postdate', '>', now()->addDay()->format('Y-m-d H:i:s'))->exists();
        if ($unknown || $reversed || $future) {
            return 'conflicting_posting_frontier';
        }

        return match (true) {
            $left === null || $containing[0] > $start => 'unknown_left_edge',
            $right === null || $containing[1] < $end => 'waiting_head_frontier',
            default => 'ready',
        };
    }
}
