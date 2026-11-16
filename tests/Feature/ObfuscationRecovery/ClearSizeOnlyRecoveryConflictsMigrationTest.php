<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ClearSizeOnlyRecoveryConflictsMigrationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const PRIMARY = '11111111-1111-4111-8111-111111111111';

    private const OTHER = '22222222-2222-4222-8222-222222222222';

    private const BASE_MS = 1700000000000;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        ProductionTables::fromAuthority()->create('usenet_groups', ['id']);
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        (require database_path('migrations/2026_10_20_120000_index_obfuscation_recovery_expired_headers_by_age.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_clears_every_primary_epoch_flag_and_rebuilds_the_affected_candidates(): void
    {
        $this->controls();
        foreach (range(0, 249) as $minute) {
            $this->header(1, $minute, true);
        }
        $this->header(2, 0, true);
        $this->header(2, 1, true);
        $this->header(1, 20, true, generation: 2);
        $unflagged = $this->header(1, 300, false, 5);
        $first = $this->candidate(1, 10);
        $second = $this->candidate(2, 1);
        $laterGeneration = $this->candidate(1, 20, 2);

        $this->migration()->up();

        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->where('metadata_conflict', true)->count());
        $this->assertTrue(DB::table('obfuscation_recovery_dirty')->where('scope_digest', $this->scope(1, 4))->exists());
        $this->assertTrue(DB::table('obfuscation_recovery_dirty')->where('scope_digest', $this->scope(2, 4))->exists());
        $this->assertFalse(DB::table('obfuscation_recovery_dirty')->where('scope_digest', $this->scope(1, 5))->exists());
        $this->assertFalse((bool) DB::table('obfuscation_recovery_headers')->where('id', $unflagged)->value('metadata_conflict'));
        $this->assertTrue(DB::table('obfuscation_recovery_dirty')->where('scope_digest', $this->scope(1, 4, 2))->exists());
        foreach ([$first, $second, $laterGeneration] as $id) {
            $candidate = DB::table('obfuscation_recovery_bundles')->where('id', $id)->first();
            $this->assertSame(2, (int) $candidate->revision);
            $this->assertSame('late_membership_revision', $candidate->reason);
        }

        $dirty = DB::table('obfuscation_recovery_dirty')->orderBy('id')->get(['id', 'version'])->all();
        $this->migration()->up();

        $this->assertEquals($dirty, DB::table('obfuscation_recovery_dirty')->orderBy('id')->get(['id', 'version'])->all());
        $this->assertSame([2, 2, 2], DB::table('obfuscation_recovery_bundles')->orderBy('id')->pluck('revision')->map(fn ($revision): int => (int) $revision)->all());
    }

    public function test_flags_in_another_source_epoch_stay_set(): void
    {
        $this->controls();
        $other = $this->header(1, 0, true, 4, self::OTHER);
        $primary = $this->header(1, 1, true);

        $this->migration()->up();

        $this->assertTrue((bool) DB::table('obfuscation_recovery_headers')->where('id', $other)->value('metadata_conflict'));
        $this->assertFalse((bool) DB::table('obfuscation_recovery_headers')->where('id', $primary)->value('metadata_conflict'));
    }

    public function test_without_a_primary_control_row_it_changes_nothing(): void
    {
        $this->header(1, 0, true);
        $candidate = $this->candidate(1, 0);

        $this->migration()->up();

        $this->assertTrue((bool) DB::table('obfuscation_recovery_headers')->value('metadata_conflict'));
        $this->assertSame(0, DB::table('obfuscation_recovery_dirty')->count());
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_bundles')->where('id', $candidate)->value('revision'));
    }

    public function test_a_batch_over_the_invalidation_cap_throws_and_keeps_its_flags(): void
    {
        $this->controls();
        $this->header(1, 0, true);
        $this->header(1, 1, true);
        foreach (range(1, 1001) as $ordinal) {
            $this->candidate(1, 0);
        }

        try {
            $this->migration()->up();
            $this->fail('The migration must stop at the invalidation cap.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('capture_invalidation_cap', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('obfuscation_recovery_headers')->where('metadata_conflict', true)->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_dirty')->count());
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_16_120000_clear_size_only_recovery_header_conflicts.php');
    }

    private function controls(): void
    {
        foreach (['primary' => self::PRIMARY, 'group:1' => self::PRIMARY, 'group:2' => self::PRIMARY] as $scope => $epoch) {
            DB::table('obfuscation_recovery_controls')->insert([
                'scope' => $scope, 'fingerprint' => str_repeat('a', 64), 'epoch' => $epoch, 'generation' => 1, 'updated_at' => now(),
            ]);
        }
    }

    private function header(int $group, int $minute, bool $flagged, int $total = 4, string $epoch = self::PRIMARY, int $generation = 1): int
    {
        $ordinal = ++$this->sequence;
        $timestamp = self::BASE_MS + $minute * 60000;
        $id = 'm'.$ordinal.'-'.$timestamp.'@nyuu';

        return DB::table('obfuscation_recovery_headers')->insertGetId([
            'source_epoch' => $epoch, 'groups_id' => $group, 'capture_generation' => $generation, 'message_id' => $id,
            'source_message_id' => '<'.$id.'>', 'message_id_digest' => hash('sha256', $id), 'article_number' => 4000000000 + $ordinal,
            'raw_subject' => '[1] - '.str_repeat('a', 32).' yEnc (1/'.$total.')', 'parsed_name' => '[1] - '.str_repeat('a', 32).' yEnc',
            'poster_identity' => 'fixture@example.invalid', 'source_date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'postdate' => '2023-11-14 22:13:20',
            'xref' => '', 'advertised_bytes' => 740000, 'original_part' => 1, 'advertised_total' => $total,
            'embedded_timestamp_ms' => $timestamp, 'profile' => RecoveryAlgorithm::Media->value, 'key_digest' => str_repeat('b', 64),
            'metadata_conflict' => $flagged, 'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
    }

    private function candidate(int $group, int $minute, int $generation = 1): int
    {
        $timestamp = self::BASE_MS + $minute * 60000;

        return DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => hash('sha256', 'candidate-'.(++$this->sequence)), 'kind' => 'posting', 'profile' => RecoveryAlgorithm::Media->value,
            'groups_id' => $group, 'source_epoch' => self::PRIMARY, 'capture_generation' => $generation, 'revision' => 1,
            'start_ms' => $timestamp - 1000, 'end_ms' => $timestamp + 1000, 'state' => 'collecting', 'reason' => 'ambiguous_media_membership',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function scope(int $group, int $total, int $generation = 1): string
    {
        return (new RecoveryIdentity)->digest([self::PRIMARY, (string) $group, (string) $generation, RecoveryAlgorithm::Media->value, (string) $total]);
    }
}
