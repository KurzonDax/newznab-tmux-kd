<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecoveryRunDiscovery
{
    /** @return array<string,mixed>|null */
    public function scan(object $dirty): ?array
    {
        $algorithm = RecoveryAlgorithm::tryFrom($dirty->profile) ?? throw new InvalidArgumentException('invalid_discovery_profile');
        $base = $this->headers($dirty);
        $seed = (clone $base)->whereBetween('embedded_timestamp_ms', [$dirty->first_ms, $dirty->last_ms])
            ->orderBy('embedded_timestamp_ms')->orderBy('message_id')->first();
        if ($seed === null) {
            return null;
        }
        $cursor = new RecoveryHeaderCursor;
        $start = $previous = (int) $seed->embedded_timestamp_ms;
        $count = 0;
        $leftFailure = null;
        $before = (clone $base)->whereBetween('embedded_timestamp_ms', [max(0, $start - 21603000), $start]);
        foreach ($cursor->rows($before, true) as $row) {
            $timestamp = (int) $row->embedded_timestamp_ms;
            if ($previous - $timestamp > 3000) {
                break;
            }
            $start = $previous = $timestamp;
            $count++;
            if ((int) $seed->embedded_timestamp_ms - $start > 21600000 || $count > 500000) {
                $leftFailure = $count > 500000 ? 'candidate_count_cap' : 'candidate_span_cap';
                break;
            }
        }
        if ($leftFailure !== null) {
            $start = (int) $seed->embedded_timestamp_ms;
        }
        $rows = $this->oneRun($cursor->rows((clone $base)->where('embedded_timestamp_ms', '>=', $start)));
        $formation = new RecoveryFormation;
        $runs = $algorithm === RecoveryAlgorithm::Media ? $formation->mediaRuns($rows) : $formation->rarRuns($rows);
        foreach ($runs as $run) {
            if ($leftFailure !== null) {
                $run['state'] = $leftFailure;
            }
            $run['left_complete'] = $leftFailure === null;

            return $run;
        }

        return null;
    }

    public function headers(object $scope): Builder
    {
        $query = DB::table('obfuscation_recovery_headers')->where('source_epoch', $scope->source_epoch)
            ->where('groups_id', $scope->groups_id)->where('capture_generation', $scope->capture_generation)
            ->where('profile', $scope->profile);
        if ($scope->profile === RecoveryAlgorithm::Media->value) {
            $query->where('advertised_total', (int) $scope->partition_value);
        } else {
            $query->where('key_digest', $scope->partition_value);
        }

        return $query;
    }

    /** @param iterable<object> $rows
     * @return Generator<int,object>
     */
    private function oneRun(iterable $rows): Generator
    {
        $first = $previous = null;
        foreach ($rows as $row) {
            $timestamp = (int) $row->embedded_timestamp_ms;
            if ($previous !== null && $timestamp - $previous > 3000) {
                break;
            }
            $first ??= $timestamp;
            yield $row;
            if ($timestamp - $first > 21600000) {
                break;
            }
            $previous = $timestamp;
        }
    }
}
