<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBundleRefresh;
use App\Services\ObfuscationRecovery\RecoveryComponents;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryComponentsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_components_follow_running_maximum_end_and_retain_inexact_competitors(): void
    {
        $long = $this->runRow(1, 1000, 121000, 'short');
        $nested = $this->runRow(2, 10000, 11000, 'count_exact');
        $connected = $this->runRow(3, 130000, 131000, 'overfull');
        $separate = $this->runRow(4, 161001, 162000, 'count_exact');
        $component = (new RecoveryComponents)->forRun($nested);
        $this->assertSame([$long->id, $nested->id, $connected->id], array_column($component, 'id'));
        $this->assertSame(['short', 'count_exact', 'overfull'], array_column($component, 'state'));
        $this->assertSame([$separate->id], array_column((new RecoveryComponents)->forRun($separate), 'id'));
    }

    public function test_rar_runs_remain_independent_of_temporally_adjacent_runs(): void
    {
        $first = $this->runRow(1, 1000, 2000, 'candidate', RecoveryAlgorithm::Rar);
        $this->runRow(2, 6000, 7000, 'candidate', RecoveryAlgorithm::Rar);
        $this->assertSame([$first->id], array_column((new RecoveryComponents)->forRun($first), 'id'));
    }

    public function test_a_late_bridge_preserves_both_candidate_budgets_under_one_owner(): void
    {
        DB::table('obfuscation_recovery_controls')->insert([
            ['scope' => 'primary', 'fingerprint' => str_repeat('a', 64), 'epoch' => 'epoch', 'generation' => 1, 'updated_at' => now()],
            ['scope' => 'group:1', 'fingerprint' => str_repeat('b', 64), 'epoch' => 'group', 'generation' => 1, 'updated_at' => now()],
        ]);
        $left = $this->runRow(1, 1000, 2000, 'count_exact');
        $right = $this->runRow(2, 40000, 41000, 'count_exact');
        $refresh = new RecoveryBundleRefresh(new RecoveryComponents);
        $leftId = $refresh->refresh((int) $left->id);
        $rightId = $refresh->refresh((int) $right->id);
        $this->assertNotSame($leftId, $rightId);
        $leftOwner = DB::table('obfuscation_recovery_bundles')->where('id', $leftId)->value('owner_digest');
        $rightOwner = DB::table('obfuscation_recovery_bundles')->where('id', $rightId)->value('owner_digest');
        foreach ([$leftId => 'a', $rightId => 'b'] as $id => $file) {
            DB::table('obfuscation_recovery_bundles')->where('id', $id)->update(['construction_targets' => json_encode([
                ['kind' => 'index', 'file_id' => null, 'message_id' => 'index@local'],
                ['kind' => 'anchor', 'file_id' => str_repeat($file, 32), 'message_id' => $file.'@local'],
            ])]);
        }
        $budget = app(RecoveryBudget::class);
        $budget->reserve($leftOwner, 'construction', 'left-target', 128, 400);
        $budget->reserve($rightOwner, 'construction', 'right-target', 128, 400);
        $bridge = $this->runRow(3, 20000, 21000, 'short');
        $survivor = $refresh->refresh((int) $bridge->id);
        $this->assertSame($leftId, $survivor);
        $this->assertSame(256, $budget->spent($leftOwner, 'construction'));
        $this->assertSame('coalesced', DB::table('obfuscation_recovery_bundles')->where('id', $rightId)->value('state'));
        $row = DB::table('obfuscation_recovery_bundles')->where('id', $survivor)->first();
        $this->assertCount(3, json_decode($row->candidate_runs, true));
        $this->assertCount(3, json_decode($row->construction_targets, true));
        $this->assertSame(2, (int) $row->revision);
        $this->assertSame(0, DB::table('obfuscation_recovery_runs')->where('bundle_dirty', true)->count());
        $this->assertSame($survivor, $refresh->refresh((int) $bridge->id));
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_bundles')->where('id', $survivor)->value('revision'));
    }

    private function runRow(int $id, int $start, int $end, string $state, RecoveryAlgorithm $profile = RecoveryAlgorithm::Media): object
    {
        DB::table('obfuscation_recovery_runs')->insert([
            'id' => $id, 'run_identity' => hash('sha256', (string) $id), 'scope_digest' => hash('sha256', 'scope'.$id),
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1, 'profile' => $profile->value,
            'partition_value' => (string) $id, 'start_ms' => $start, 'end_ms' => $end, 'observed_count' => 1,
            'state' => $state, 'membership_digest' => hash('sha256', 'members'.$id),
            'summary' => json_encode(['membership_changed_at' => now()->format('Y-m-d H:i:s.u')]),
            'oldest_observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('obfuscation_recovery_runs')->where('id', $id)->first();
    }
}
