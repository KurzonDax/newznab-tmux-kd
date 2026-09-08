<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryRarNomination;
use PHPUnit\Framework\TestCase;

final class RecoveryRarNominationTest extends TestCase
{
    public function test_nomination_derives_all_volume_boundaries_and_index_from_raw_sizes(): void
    {
        $rows = $this->rows([740000, 740000, 100000, 740000, 740000, 100000, 740000, 740000, 100000, 740000, 100000, 1200, 740000]);
        $plan = (new RecoveryRarNomination)->nominate(fn (): array => $rows);
        $this->assertSame([[1, 3], [4, 6], [7, 9], [10, 11]], $plan['blocks']);
        $this->assertSame(12, $plan['index_ordinal']);
        $this->assertSame('m12@fixture', $plan['index_message_id']);
        $this->assertSame([3, 3, 2], [$plan['block_length'], $plan['train_length'], $plan['final_length']]);
    }

    public function test_threshold_disagreement_is_unsupported_without_relaxation(): void
    {
        $rows = $this->rows([740000, 740000, 100000, 740000, 740000, 100000, 740000, 740000, 100000, 740000, 400000, 1200]);
        $this->expectExceptionMessage('unsupported_boundary_signature');
        (new RecoveryRarNomination)->nominate(fn (): array => $rows);
    }

    public function test_ties_may_exist_inside_a_volume_but_never_cross_a_boundary(): void
    {
        $rows = $this->rows([740000, 740000, 100000, 740000, 740000, 100000, 740000, 740000, 100000, 740000, 100000, 1200]);
        $rows[1]->embedded_timestamp_ms = $rows[0]->embedded_timestamp_ms;
        $this->assertSame(12, (new RecoveryRarNomination)->nominate(fn (): array => $rows)['index_ordinal']);
        $rows[3]->embedded_timestamp_ms = $rows[2]->embedded_timestamp_ms;
        $this->expectExceptionMessage('timestamp_boundary_tie');
        (new RecoveryRarNomination)->nominate(fn (): array => $rows);
    }

    public function test_integer_thresholds_do_not_overflow_large_advertised_sizes(): void
    {
        $large = PHP_INT_MAX - 1;
        $rows = $this->rows([$large, $large, 1, $large, $large, 1, $large, $large, 1, $large, 1, 1]);
        $this->assertSame(12, (new RecoveryRarNomination)->nominate(fn (): array => $rows)['index_ordinal']);
    }

    /** @param list<int> $sizes
     * @return list<object>
     */
    private function rows(array $sizes): array
    {
        $rows = [];
        foreach ($sizes as $index => $bytes) {
            $rows[] = (object) ['message_id' => 'm'.($index + 1).'@fixture', 'advertised_bytes' => $bytes,
                'embedded_timestamp_ms' => 1700000000000 + $index * 100, 'metadata_conflict' => false];
        }

        return $rows;
    }
}
