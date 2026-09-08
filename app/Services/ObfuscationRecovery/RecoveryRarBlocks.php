<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use InvalidArgumentException;

final class RecoveryRarBlocks
{
    /** @param iterable<object> $rows
     * @param  list<array{int,int}>  $boundaries
     * @return list<array<string,mixed>>
     */
    public function read(iterable $rows, array $boundaries): array
    {
        $blocks = [];
        $ordinal = $block = 0;
        foreach ($rows as $row) {
            $ordinal++;
            if (! isset($boundaries[$block])) {
                break;
            }
            [$start, $end] = $boundaries[$block];
            $timestamp = (int) $row->embedded_timestamp_ms;
            $blocks[$block] ??= ['start_ordinal' => $start, 'end_ordinal' => $end, 'observed_count' => 0,
                'start_ms' => $timestamp, 'end_ms' => $timestamp, 'earliest' => [], 'terminal_count' => 0, 'terminal_message_id' => ''];
            $current = &$blocks[$block];
            $current['observed_count']++;
            if ($timestamp === $current['start_ms']) {
                $current['earliest'][] = ['message_id' => $row->message_id, 'embedded_timestamp_ms' => $timestamp];
                if (count($current['earliest']) > 8) {
                    throw new InvalidArgumentException('anchor_target_cap');
                }
            }
            $current['terminal_count'] = $timestamp === $current['end_ms'] ? $current['terminal_count'] + 1 : 1;
            $current['end_ms'] = $timestamp;
            $current['terminal_message_id'] = $row->message_id;
            unset($current);
            if ($ordinal === $end) {
                $block++;
            }
        }
        if ($block !== count($boundaries)) {
            throw new InvalidArgumentException('incomplete_rar_blocks');
        }

        return $blocks;
    }
}
