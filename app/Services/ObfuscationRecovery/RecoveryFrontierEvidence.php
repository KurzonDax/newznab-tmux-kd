<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

final class RecoveryFrontierEvidence
{
    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope */
    public function answer(Connection $connection, string $scope, int $first, int $last, array $envelope): string
    {
        $rows = $connection->table('obfuscation_recovery_frontier_ranges')->where('scope_digest', $scope)
            ->where('evidence_version', RecoveryFrontiers::VERSION)->where('first_article', '<=', $last)
            ->where('last_article', '>=', $first)->orderBy('first_article')->limit(1001)->get();
        if ($rows->count() > 1000) {
            return 'evidence_context_limit';
        }
        $ranges = $rows->map(static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all();
        if (RecoveryCoverage::holes($first, $last, $ranges) !== []) {
            return 'legacy_evidence';
        }
        foreach ([['left', $first, min($last, $envelope['first_article'] - 1)],
            ['right', max($first, $envelope['last_article'] + 1), $last]] as [$side, $start, $end]) {
            if ($start > $end) {
                continue;
            }
            $threshold = $side === 'left' ? Carbon::parse($envelope['first_postdate'], 'UTC')->subMinutes(120)->format('Y-m-d H:i:s')
                : Carbon::parse($envelope['last_postdate'], 'UTC')->addMinutes(120)->format('Y-m-d H:i:s');
            $witness = RecoveryFrontiers::witnesses($connection, $scope)->whereBetween('article_number', [$start, $end])
                ->where('postdate', $side === 'left' ? '<=' : '>=', $threshold)->exists();
            if ($witness) {
                continue;
            }
            $examined = [];
            foreach ($rows as $row) {
                if ($row->head_observed && ($row->exhaustive || ((int) $row->first_article >= $start && (int) $row->last_article <= $end))) {
                    $points = json_decode($row->points, true, flags: JSON_THROW_ON_ERROR);
                    if (! $row->exhaustive && count((new RecoveryFrontiers)->usable($connection, $scope, $points)) !== count($points)) {
                        continue;
                    }
                    $examined[] = [(int) $row->first_article, (int) $row->last_article];
                }
            }
            if (RecoveryCoverage::holes($start, $end, $examined) !== []) {
                return 'insufficient_summary';
            }
        }

        return 'examined';
    }

    public function retainedHead(Connection $connection, object $bundle, int $first, int $last, bool $sealed): bool
    {
        $windows = $connection->table('obfuscation_recovery_scan_windows')->where('source_epoch', $bundle->source_epoch)
            ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)
            ->where('requested_first', '<=', $last)->where('requested_last', '>=', $first)
            ->when(! $sealed, fn ($query) => $query->where('expires_at', '>', now()))
            ->orderBy('requested_first')->limit(1001)->get();
        if ($windows->count() > 1000 || RecoveryCoverage::holes($first, $last, $windows->map(
            static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all()) !== []) {
            return false;
        }
        if (! $sealed) {
            return true;
        }
        $scope = RecoveryPositiveCoverage::scope($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation);
        $ranges = $connection->table('obfuscation_recovery_coverage')->where('scope_digest', $scope)->where('kind', 'retained')
            ->where('direction', 'Head')->where('first_article', '<=', $last)->where('last_article', '>=', $first)
            ->orderBy('first_article')->limit(1001)->get();

        return $ranges->count() <= 1000 && RecoveryCoverage::holes($first, $last, $ranges->map(
            static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all()) === [];
    }
}
