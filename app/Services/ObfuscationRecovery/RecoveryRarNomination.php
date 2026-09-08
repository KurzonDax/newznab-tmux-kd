<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use Closure;
use InvalidArgumentException;

final class RecoveryRarNomination
{
    /**
     * @param  Closure(): iterable<object>  $source  Repeatable immutable run, in source order.
     * @return array{blocks: list<array{int,int}>, index_ordinal: int, index_message_id: string, block_length: int, train_length: int, final_length: int}
     */
    public function nominate(Closure $source): array
    {
        $sizes = [];
        $digest = hash_init('sha256');
        $previous = $firstTimestamp = null;
        foreach ($source() as $row) {
            $bytes = (int) $row->advertised_bytes;
            $timestamp = (int) $row->embedded_timestamp_ms;
            $firstTimestamp ??= $timestamp;
            if ($bytes <= 0 || $row->metadata_conflict) {
                throw new InvalidArgumentException('invalid_rar_boundary_evidence');
            }
            if ($previous !== null && ($timestamp < (int) $previous->embedded_timestamp_ms
                || ($timestamp === (int) $previous->embedded_timestamp_ms && strcmp($row->message_id, $previous->message_id) <= 0))) {
                throw new InvalidArgumentException('unsorted_recovery_headers');
            }
            if ($previous !== null && $timestamp - (int) $previous->embedded_timestamp_ms > 3000) {
                throw new InvalidArgumentException('multiple_rar_runs');
            }
            $sizes[] = $bytes;
            if (count($sizes) > 500000 || $timestamp - $firstTimestamp > 21600000) {
                throw new InvalidArgumentException('candidate_structural_cap');
            }
            hash_update($digest, $this->projection($row));
            $previous = $row;
        }
        $count = count($sizes);
        if ($count < 9) {
            throw new InvalidArgumentException('unsupported_boundary_signature');
        }
        sort($sizes, SORT_NUMERIC);
        $a = $sizes[intdiv($count - 1, 2)];
        $b = $sizes[intdiv($count, 2)];
        unset($sizes);
        $original = hash_final($digest);
        $digest = hash_init('sha256');
        $small = [4 => [], 6 => [], 8 => []];
        $ordinal = 0;
        foreach ($source() as $row) {
            $ordinal++;
            if ($ordinal > $count) {
                throw new InvalidArgumentException('changed_rar_snapshot');
            }
            hash_update($digest, $this->projection($row));
            foreach ([4, 6, 8] as $factor) {
                $last = count($small[$factor]) - 1;
                if ($last >= 0 && $small[$factor][$last]['ordinal'] === $ordinal - 1) {
                    $small[$factor][$last]['next_timestamp'] = (int) $row->embedded_timestamp_ms;
                }
                if (count($small[$factor]) < 34 && $this->isSmall((int) $row->advertised_bytes, $a, $b, $factor)) {
                    $small[$factor][] = ['ordinal' => $ordinal, 'message_id' => $row->message_id,
                        'timestamp' => (int) $row->embedded_timestamp_ms, 'next_timestamp' => null];
                }
            }
        }
        if ($ordinal !== $count || hash_final($digest) !== $original) {
            throw new InvalidArgumentException('changed_rar_snapshot');
        }
        $plans = array_map($this->thresholdPlan(...), $small);
        if ($plans[4] !== $plans[6] || $plans[4] !== $plans[8]) {
            throw new InvalidArgumentException('unsupported_boundary_signature');
        }

        return $plans[4];
    }

    private function projection(object $row): string
    {
        return (new RecoveryIdentity)->digest([$row->message_id, (string) $row->advertised_bytes,
            (string) $row->embedded_timestamp_ms, (string) $row->metadata_conflict]);
    }

    private function isSmall(int $bytes, int $a, int $b, int $factor): bool
    {
        $remainder = (($a % 20) + ($b % 20)) * $factor;
        $whole = intdiv($a, 20) * $factor + intdiv($b, 20) * $factor + intdiv($remainder, 20);

        return $bytes < $whole || ($bytes === $whole && $remainder % 20 !== 0);
    }

    /** @param list<array{ordinal:int,message_id:string,timestamp:int,next_timestamp:?int}> $small
     * @return array{blocks: list<array{int,int}>, index_ordinal: int, index_message_id: string, block_length: int, train_length: int, final_length: int}
     */
    private function thresholdPlan(array $small): array
    {
        $length = $small[0]['ordinal'] ?? 0;
        if ($length < 2) {
            throw new InvalidArgumentException('unsupported_boundary_signature');
        }
        $train = 1;
        while (isset($small[$train]) && $small[$train]['ordinal'] === ($train + 1) * $length) {
            $train++;
        }
        $q = $small[$train]['ordinal'] ?? 0;
        if ($train < 3 || $train > 31 || $q <= $train * $length || $q >= ($train + 1) * $length
            || ($small[$train + 1]['ordinal'] ?? 0) !== $q + 1) {
            throw new InvalidArgumentException('unsupported_boundary_signature');
        }
        $blocks = [];
        $start = 1;
        for ($index = 0; $index <= $train; $index++) {
            $end = $small[$index];
            if ($end['next_timestamp'] === null || $end['timestamp'] >= $end['next_timestamp']) {
                throw new InvalidArgumentException('timestamp_boundary_tie');
            }
            $blocks[] = [$start, $end['ordinal']];
            $start = $end['ordinal'] + 1;
        }

        return ['blocks' => $blocks, 'index_ordinal' => $q + 1, 'index_message_id' => $small[$train + 1]['message_id'],
            'block_length' => $length, 'train_length' => $train, 'final_length' => $q - $train * $length];
    }
}
