<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryFormation;
use PHPUnit\Framework\TestCase;

final class RecoveryFormationTest extends TestCase
{
    public function test_media_runs_preserve_short_exact_overfull_and_strict_gap_boundaries(): void
    {
        $rows = [$this->row(1, 1, 0), $this->row(2, 1, 3000), $this->row(3, 1, 6001),
            $this->row(4, 3, 100), $this->row(5, 3, 1000)];
        $runs = iterator_to_array((new RecoveryFormation)->mediaRuns($rows));
        $this->assertSame(['overfull', 'count_exact', 'short'], array_column($runs, 'state'));
        $this->assertSame([2, 1, 2], array_column($runs, 'observed_count'));
        $this->assertSame([0, 6001, 100], array_column($runs, 'start_ms'));
    }

    public function test_temporal_bundles_use_running_maximum_and_include_inexact_runs(): void
    {
        $formation = new RecoveryFormation;
        $runs = [
            ['start_ms' => 0, 'end_ms' => 100000, 'state' => 'overfull'],
            ['start_ms' => 100, 'end_ms' => 1000, 'state' => 'short'],
            ['start_ms' => 129999, 'end_ms' => 130000, 'state' => 'count_exact'],
            ['start_ms' => 160001, 'end_ms' => 160001, 'state' => 'count_exact'],
        ];
        $bundles = iterator_to_array($formation->mediaBundles($runs));
        $this->assertSame([3, 1], array_map(count(...), $bundles));
    }

    public function test_unsorted_source_rows_are_rejected_instead_of_silently_reordered(): void
    {
        $this->expectExceptionMessage('unsorted_recovery_headers');
        iterator_to_array((new RecoveryFormation)->mediaRuns([$this->row(1, 4, 100), $this->row(2, 4, 99)]));
    }

    public function test_duplicate_observation_time_does_not_change_membership_digest_or_quiet_clock(): void
    {
        $row = $this->row(1, 1, 0);
        $first = iterator_to_array((new RecoveryFormation)->mediaRuns([$row]))[0];
        $row->last_observed_at = '2026-09-07 15:00:00';
        $second = iterator_to_array((new RecoveryFormation)->mediaRuns([$row]))[0];
        $this->assertSame($first, $second);
    }

    private function row(int $id, int $total, int $timestamp): object
    {
        return (object) ['id' => $id, 'advertised_total' => $total, 'message_id' => 'm'.$id.'@fixture',
            'embedded_timestamp_ms' => $timestamp, 'article_number' => 4000000000 + $id,
            'advertised_bytes' => 740000, 'metadata_conflict' => false, 'postdate' => '2026-09-07 12:00:00',
            'first_observed_at' => '2026-09-07 12:00:00', 'last_observed_at' => '2026-09-07 12:00:00'];
    }
}
