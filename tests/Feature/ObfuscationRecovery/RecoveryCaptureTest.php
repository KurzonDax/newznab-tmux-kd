<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Enums\HeaderScanDirection;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\HeaderParser;
use App\Services\NNTP\NntpProvider;
use App\Services\ObfuscationRecovery\RecoveryCapture;
use App\Services\ObfuscationRecovery\RecoveryCaptureBatch;
use App\Services\ObfuscationRecovery\RecoveryConfig;
use App\Services\ObfuscationRecovery\RecoveryControl;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryRetention;
use App\Services\ObfuscationRecovery\RecoveryScanContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\TestCase;

final class RecoveryCaptureTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'both']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_capture_keeps_both_inputs_without_changing_normal_parser_output(): void
    {
        $raw = [$this->header(1, '[a] - '.str_repeat('a', 32).' yEnc (1/4)'), $this->header(2, '0123456789abcdefghij')];
        $policy = new NeverBlacklistedService;
        $parsed = (new HeaderParser($policy))->parse($raw, 'alt.binaries.fixture');
        $this->assertCount(1, $parsed['headers']);
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), $policy);
        $batch = new RecoveryCaptureBatch($raw, $parsed['headers']);
        $context = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 4000000001, 4000000002, HeaderScanDirection::Head, 'scan-a');
        $report = $capture->capture($batch, $context);
        $this->assertSame('captured', $report->outcome);
        $this->assertSame(2, $report->captured);
        $this->assertTrue($report->coverageComplete);
        $this->assertSame('[a] - '.str_repeat('a', 32).' yEnc', $parsed['headers'][0]['matches'][1]);
        $this->assertSame(1, DB::table('obfuscation_recovery_headers')->whereNull('original_part')->count());
        $replay = $capture->capture($batch, $context);
        $this->assertSame(0, $replay->captured);
        $this->assertSame(2, $replay->duplicates);
        $this->assertSame(2, DB::table('obfuscation_recovery_headers')->count());
    }

    public function test_fresh_capture_generation_reuses_identity_without_refreshing_retention(): void
    {
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $raw = [$this->header(1, '0123456789abcdefghij')];
        $first = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1, 4000000001, 4000000001, HeaderScanDirection::Head, 'generation-one');
        $this->assertSame('captured', $capture->capture(new RecoveryCaptureBatch($raw, []), $first)->outcome);
        $observed = DB::table('obfuscation_recovery_headers')->value('first_observed_at');
        $this->travel(1)->hours();
        $next = new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 2, 4000000001, 4000000001, HeaderScanDirection::Head, 'generation-two');
        $this->assertSame('captured', $capture->capture(new RecoveryCaptureBatch($raw, []), $next)->outcome);
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_headers')->value('capture_generation'));
        $this->assertSame($observed, DB::table('obfuscation_recovery_headers')->value('first_observed_at'));
        $this->assertSame(1, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_dirty')->where('capture_generation', 2)->count());
    }

    public function test_purged_headers_cannot_restart_their_retention_window_on_recapture(): void
    {
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]);
        $capture = new RecoveryCapture($config, new NeverBlacklistedService);
        $raw = [$this->header(1, '0123456789abcdefghij')];
        $context = fn (string $scan): RecoveryScanContext => new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1,
            4000000001, 4000000001, HeaderScanDirection::Head, $scan);
        $this->assertSame(1, $capture->capture(new RecoveryCaptureBatch($raw, []), $context('original'))->captured);
        $this->travel(145)->hours();
        $this->assertSame(1, app(RecoveryRetention::class)->purge($config)['headers']);
        $again = $capture->capture(new RecoveryCaptureBatch($raw, []), $context('replayed'));
        $this->assertSame(0, $again->captured);
        $this->assertSame(1, $again->exclusions['retention_expired']);
        $this->assertFalse($again->coverageComplete);
        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->count());
    }

    public function test_new_membership_fences_a_ready_revision_but_preserves_an_existing_publication(): void
    {
        $identity = new RecoveryIdentity;
        $key = $identity->digest(['0123456789abcdefghij', 'fixture@example.invalid']);
        $bundle = DB::table('obfuscation_recovery_bundles')->insertGetId([
            'owner_digest' => str_repeat('a', 64), 'profile' => 'nyuu-rar-sequential-v1', 'groups_id' => 1,
            'source_epoch' => 'epoch', 'capture_generation' => 1, 'key_digest' => $key, 'revision' => 1,
            'start_ms' => 1700000000000, 'end_ms' => 1700000000000, 'state' => 'ready', 'sealed_plan' => '{}',
            'manifest_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $context = fn (int $id): RecoveryScanContext => new RecoveryScanContext(1, 'alt.binaries.fixture', 'epoch', 1,
            4000000000 + $id, 4000000000 + $id, HeaderScanDirection::Head, 'late-'.$id);
        $this->assertSame('captured', $capture->capture(new RecoveryCaptureBatch([$this->header(1, '0123456789abcdefghij')], []), $context(1))->outcome);
        $row = DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->first();
        $this->assertSame(2, (int) $row->revision);
        $this->assertNull($row->sealed_plan);
        $this->assertNull($row->manifest_verified_at);
        $this->assertSame('collecting', $row->state);
        $capture->capture(new RecoveryCaptureBatch([$this->header(1, '0123456789abcdefghij')], []), $context(1));
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_bundles')->value('revision'));
        DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->update(['state' => 'published', 'sealed_plan' => '{"immutable":true}']);
        $capture->capture(new RecoveryCaptureBatch([$this->header(2, '0123456789abcdefghij')], []), $context(2));
        $row = DB::table('obfuscation_recovery_bundles')->where('id', $bundle)->first();
        $this->assertSame('published', $row->state);
        $this->assertSame('{"immutable":true}', $row->sealed_plan);
        $this->assertSame(2, (int) $row->revision);
        $this->assertSame('late_membership_conflict', $row->reason);
    }

    public function test_recovery_failure_cannot_replace_or_rollback_an_ordinary_transaction(): void
    {
        $raw = [$this->header(1, '0123456789abcdefghij')];
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $ordinary = DB::connection();
        $ordinary->statement('PRAGMA busy_timeout=100');
        $ordinary->beginTransaction();
        $ordinary->table('settings')->insert(['name' => 'ordinary_progress', 'value' => 'retained']);
        $report = $capture->capture(new RecoveryCaptureBatch($raw, []), new RecoveryScanContext(
            1, 'alt.binaries.fixture', 'epoch', 1, 4000000001, 4000000001, HeaderScanDirection::Head, 'scan-lock',
        ));
        $this->assertSame('capture_failed', $report->outcome);
        $this->assertSame($ordinary, DB::connection());
        $this->assertSame(1, $ordinary->transactionLevel());
        $ordinary->commit();
        $this->assertSame('retained', DB::table('settings')->where('name', 'ordinary_progress')->value('value'));
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->count());
    }

    public function test_disabled_group_does_not_record_positive_coverage(): void
    {
        DB::table('usenet_groups')->update(['obfuscation_recovery_profile' => 'disabled']);
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $report = $capture->capture(new RecoveryCaptureBatch([], []), new RecoveryScanContext(
            1, 'alt.binaries.fixture', 'epoch', 1, 1, 100, HeaderScanDirection::Head, 'scan-off',
        ));
        $this->assertSame('disabled', $report->outcome);
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->count());
    }

    public function test_reversed_chunks_require_every_durable_chunk_including_empty_matches(): void
    {
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $context = fn (int $ordinal): RecoveryScanContext => new RecoveryScanContext(
            1, 'alt.binaries.fixture', 'epoch', 1, 4000000001, 4000000003, HeaderScanDirection::Head, 'scan-chunks', $ordinal, 2,
        );
        $last = $capture->capture(new RecoveryCaptureBatch([$this->header(3, 'ordinary text')], []), $context(1));
        $this->assertSame('captured', $last->outcome);
        $this->assertFalse($last->coverageComplete);
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
        $first = $capture->capture(new RecoveryCaptureBatch([$this->header(1, 'ordinary text')], []), $context(0));
        $this->assertTrue($first->coverageComplete);
        $this->assertSame(0, DB::table('obfuscation_recovery_headers')->count());
        $this->assertSame(2, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
        $this->assertSame([[4000000002, 4000000002]], json_decode(DB::table('obfuscation_recovery_scans')->value('missing_ranges'), true));
    }

    public function test_context_collision_and_out_of_range_response_cannot_create_coverage(): void
    {
        $capture = new RecoveryCapture(RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]), new NeverBlacklistedService);
        $context = fn (int $last): RecoveryScanContext => new RecoveryScanContext(
            1, 'alt.binaries.fixture', 'epoch', 1, 4000000001, $last, HeaderScanDirection::Head, 'scan-conflict', 0, 2,
        );
        $this->assertSame('captured', $capture->capture(new RecoveryCaptureBatch([], []), $context(4000000001))->outcome);
        $this->assertSame('capture_failed', $capture->capture(new RecoveryCaptureBatch([], []), $context(4000000002))->outcome);
        $this->assertSame('capture_failed', $capture->capture(new RecoveryCaptureBatch([$this->header(2, 'ordinary')], []), $context(4000000001))->outcome);
        $this->assertSame(0, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
    }

    public function test_provider_changes_and_switch_cycles_fence_old_scan_contexts(): void
    {
        $config = RecoveryConfig::fromValues(['obfuscation_recovery_enabled' => 1]);
        $begin = fn (string $host): ?RecoveryScanContext => (new RecoveryControl)->begin($config,
            NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => $host]),
            1, 'alt.binaries.fixture', 1, 100, HeaderScanDirection::Head, 1);
        $first = $begin('one.invalid');
        $same = $begin('one.invalid');
        $this->assertNotNull($first);
        $this->assertSame($first->sourceEpoch, $same->sourceEpoch);
        $this->assertSame($first->generation, $same->generation);
        $capture = new RecoveryCapture($config, new NeverBlacklistedService);
        UsenetGroup::updateSelected([1], ['obfuscation_recovery_profile' => 'disabled']);
        UsenetGroup::updateSelected([1], ['obfuscation_recovery_profile' => 'both']);
        $this->assertSame('capture_failed', $capture->capture(new RecoveryCaptureBatch([], []), $first)->outcome);
        $reenabled = $begin('one.invalid');
        $this->assertGreaterThan($first->generation, $reenabled->generation);
        DB::table('obfuscation_recovery_controls')->insert([
            'scope' => 'budget-owners', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'budget', 'generation' => 1, 'updated_at' => now(),
        ]);
        Settings::settingsUpsert(['obfuscation_recovery_enabled' => 1]);
        Settings::settingsUpsert(['obfuscation_recovery_enabled' => 0]);
        Settings::settingsUpsert(['obfuscation_recovery_enabled' => 1]);
        $this->assertSame('capture_failed', $capture->capture(new RecoveryCaptureBatch([], []), $reenabled)->outcome);
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_controls')->where('scope', 'budget-owners')->value('generation'));
        $other = $begin('two.invalid');
        $this->assertNotSame($first->sourceEpoch, $other->sourceEpoch);
        $returned = $begin('one.invalid');
        $this->assertNotSame($first->sourceEpoch, $returned->sourceEpoch);
        $this->assertSame('captured', $capture->capture(new RecoveryCaptureBatch([], []), $returned)->outcome);
    }

    /** @return array<string, mixed> */
    private function header(int $ordinal, string $subject): array
    {
        return [
            'Number' => (string) (4000000000 + $ordinal), 'Subject' => $subject,
            'From' => 'fixture@example.invalid', 'Message-ID' => 'm'.$ordinal.'-1700000000000@nyuu',
            'Date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'Bytes' => 740000, 'Xref' => '',
        ];
    }
}
