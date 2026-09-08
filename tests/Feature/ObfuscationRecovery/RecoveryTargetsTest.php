<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConstructionTargets;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryTargetsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_repeated_index_keeps_attempts_debits_and_target_history_across_provenance(): void
    {
        $work = app(RecoveryWork::class);
        $budget = app(RecoveryBudget::class);
        $targets = new RecoveryConstructionTargets;
        foreach (['group-one', 'group-two', 'new-epoch', 'new-classification'] as $scope => $owner) {
            $id = $work->enqueue(RecoveryStage::Discover, $owner, 1, 'discover', []);
            $bundleId = DB::table('obfuscation_recovery_work')->where('id', $id)->value('bundle_id');
            DB::table('obfuscation_recovery_bundles')->where('id', $bundleId)->update([
                'groups_id' => $scope + 1, 'source_epoch' => 'epoch-'.$scope,
                'profile' => RecoveryAlgorithm::Media->value,
            ]);
            $claim = $work->claim(RecoveryStage::Discover);
            $nomination = [['kind' => 'index', 'file_id' => null, 'message_id' => 'repeated@local']];
            foreach (range(1, 8) as $i) {
                $nomination[] = ['kind' => 'anchor', 'file_id' => str_repeat('a', 32), 'message_id' => 'a'.$i.'@local'];
            }
            $targets->register($claim, $nomination);
            $bundle = DB::table('obfuscation_recovery_bundles')->where('id', $bundleId)->first();
            if ($scope === 0) {
                foreach ([1, 2] as $attempt) {
                    $reservation = $budget->reserve($bundle->owner_digest, 'construction', 'repeated@local', 128, 1024);
                    $this->assertSame($attempt, $reservation->physicalAttempt);
                    $budget->settle($reservation, null, 0, 'transport_failure');
                }
            } else {
                $this->assertNull($budget->reserve($bundle->owner_digest, 'construction', 'repeated@local', 128, 1024));
                $this->assertSame(256, $budget->spent($bundle->owner_digest, 'construction'));
                try {
                    $targets->register($claim, [['kind' => 'anchor', 'file_id' => str_repeat('a', 32), 'message_id' => 'a9@local']]);
                    $this->fail('Repeated provenance reset the anchor allowance.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertSame('file_anchor_target_limit', $error->getMessage());
                }
            }
            $work->complete($claim, 'checked');
        }
        $this->assertSame(2, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_a_revision_cannot_replace_the_single_index_or_reset_per_file_target_limits(): void
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Discover, 'candidate', 1, 'discover', []);
        DB::table('obfuscation_recovery_bundles')->update(['profile' => RecoveryAlgorithm::Media->value]);
        $claim = $work->claim(RecoveryStage::Discover);
        $targets = new RecoveryConstructionTargets;
        $first = [['kind' => 'index', 'file_id' => null, 'message_id' => 'index@local']];
        foreach (range(1, 8) as $i) {
            $first[] = ['kind' => 'anchor', 'file_id' => str_repeat('a', 32), 'message_id' => 'a'.$i.'@local'];
        }
        $targets->register($claim, $first);
        $targets->register($claim, $first);
        $this->assertCount(9, json_decode(DB::table('obfuscation_recovery_bundles')->value('construction_targets'), true));
        $work->invalidate('candidate', 2);
        $work->enqueue(RecoveryStage::Discover, 'candidate', 2, 'discover', []);
        $next = $work->claim(RecoveryStage::Discover);
        foreach ([['kind' => 'index', 'file_id' => null, 'message_id' => 'other-index@local'],
            ['kind' => 'anchor', 'file_id' => str_repeat('a', 32), 'message_id' => 'a9@local']] as $extra) {
            try {
                $targets->register($next, [$extra]);
                $this->fail('A lifetime target limit was reset.');
            } catch (\InvalidArgumentException $e) {
                $this->assertContains($e->getMessage(), ['index_target_limit', 'file_anchor_target_limit']);
            }
        }
        $this->assertCount(9, json_decode(DB::table('obfuscation_recovery_bundles')->value('construction_targets'), true));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }
}
