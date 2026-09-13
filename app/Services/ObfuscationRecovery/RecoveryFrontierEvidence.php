<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

final class RecoveryFrontierEvidence
{
    /** @return array{first:int,last:int,expires_at:string}|null */
    public function interval(Connection $connection, object $bundle, int $position, bool $sealed): ?array
    {
        $partition = intdiv($position - 1, 20000) * 20000 + 1;
        $windows = RecoveryFrontierWindows::overlapping($connection, $bundle, $partition, $partition + 19999, $sealed)
            ->orderBy('requested_first')->orderBy('requested_last')->limit(1001)->get();
        if ($windows->count() > 1000) {
            return null;
        }
        $components = [];
        foreach ($windows as $window) {
            $first = max($partition, (int) $window->requested_first);
            $last = min($partition + 19999, (int) $window->requested_last);
            $index = count($components) - 1;
            if ($index >= 0 && $first <= $components[$index]['last'] + 1) {
                $components[$index]['last'] = max($last, $components[$index]['last']);
                $components[$index]['expires_at'] = min($window->expires_at, $components[$index]['expires_at']);
            } else {
                $components[] = ['first' => $first, 'last' => $last, 'expires_at' => $window->expires_at];
            }
        }
        foreach ($components as $component) {
            if ($component['first'] <= $position && $component['last'] >= $position) {
                $coverage = $connection->table('obfuscation_recovery_coverage')->where('source_epoch', $bundle->source_epoch)
                    ->where('groups_id', $bundle->groups_id)->where('capture_generation', $bundle->capture_generation)
                    ->where('kind', $sealed ? 'retained' : 'captured')->where('direction', 'Head')
                    ->where('first_article', '<=', $component['last'])->where('last_article', '>=', $component['first'])
                    ->orderBy('first_article')->limit(1001)->get();
                if ($coverage->count() > 1000) {
                    return null;
                }
                $holes = RecoveryCoverage::holes($component['first'], $component['last'], $coverage->map(
                    static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all());
                foreach ($holes as [$first, $last]) {
                    if ($first <= $position && $last >= $position) {
                        return null;
                    }
                    if ($last < $position) {
                        $component['first'] = $last + 1;
                    } else {
                        $component['last'] = min($component['last'], $first - 1);
                    }
                }

                return $component;
            }
        }

        return null;
    }

    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope */
    public function answer(Connection $connection, string $scope, int $first, int $last, array $envelope): string
    {
        foreach ((new RecoveryFrontierRequirement)->intervals($connection, $scope, $first, $last, $envelope) as [$start, $end]) {
            $answer = $this->examine($connection, $scope, $start, $end, $envelope);
            if ($answer !== 'examined') {
                return $answer;
            }
        }

        return 'examined';
    }

    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope */
    private function examine(Connection $connection, string $scope, int $first, int $last, array $envelope): string
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

    /** @param array{first_article:int,last_article:int,first_postdate:string,last_postdate:string,changed_at:string} $envelope */
    public function retire(Connection $connection, string $scope, int $first, int $last, array $envelope): bool
    {
        foreach ((new RecoveryFrontierRequirement)->intervals($connection, $scope, $first, $last, $envelope) as [$start, $end]) {
            if ($this->examine($connection, $scope, $start, $end, $envelope) !== 'examined') {
                return false;
            }
            (new RecoveryFrontiers)->replaceLegacy($connection, $scope, $start, $end);
            if (RecoveryFrontierConflicts::overlapping($connection, $scope, $start, $end, ['unknown', 'ordering'])->exists()) {
                return false;
            }
        }

        return true;
    }

    public function retainedHead(Connection $connection, object $bundle, int $first, int $last, bool $sealed): bool
    {
        $windows = RecoveryFrontierWindows::overlapping($connection, $bundle, $first, $last, $sealed)
            ->orderBy('requested_first')->limit(1001)->get();
        if ($windows->count() > 1000 || RecoveryCoverage::holes($first, $last, $windows->map(
            static fn (object $row): array => [(int) $row->requested_first, (int) $row->requested_last])->all()) !== []) {
            return false;
        }
        $scope = RecoveryPositiveCoverage::scope($bundle->source_epoch, (int) $bundle->groups_id, (int) $bundle->capture_generation);
        $ranges = $connection->table('obfuscation_recovery_coverage')->where('scope_digest', $scope)->where('kind', $sealed ? 'retained' : 'captured')
            ->where('direction', 'Head')->where('first_article', '<=', $last)->where('last_article', '>=', $first)
            ->orderBy('first_article')->limit(1001)->get();

        return $ranges->count() <= 1000 && RecoveryCoverage::holes($first, $last, $ranges->map(
            static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->all()) === [];
    }
}
