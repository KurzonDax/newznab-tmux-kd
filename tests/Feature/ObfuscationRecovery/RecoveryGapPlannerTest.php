<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Models\Settings;
use App\Services\ObfuscationRecovery\RecoveryCoverage;
use App\Services\ObfuscationRecovery\RecoveryGapPlanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryGapPlannerTest extends TestCase
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

    public function test_coverage_keeps_overlapping_older_chunks_after_a_buffer_reduction(): void
    {
        Settings::settingsUpsert(['maxmssgs' => '10000']);
        $scope = (object) ['groups_id' => 1, 'source_epoch' => 'epoch', 'capture_generation' => 1];
        $this->scan(71000, 100999);
        $this->scan(102000, 102999);
        $planner = new RecoveryGapPlanner;

        $positive = $planner->positive($scope, 100000, 104999);
        $this->assertSame([[100000, 100999], [102000, 102999]], $positive);
        $this->assertSame([[101000, 101999], [103000, 104999]], RecoveryCoverage::holes(100000, 104999, $positive));

        $this->scan(30000, 59999);
        $this->assertSame($positive, $planner->positive($scope, 100000, 104999));
    }

    private function scan(int $first, int $last): void
    {
        DB::table('obfuscation_recovery_scans')->insert([
            'scan_id' => (string) Str::uuid(), 'groups_id' => 1, 'source_epoch' => 'epoch',
            'capture_generation' => 1, 'requested_first' => $first, 'requested_last' => $last,
            'chunk_ordinal' => 0, 'expected_chunks' => 1, 'direction' => 'Head',
            'capture_outcome' => 'captured', 'complete' => true, 'created_at' => now(),
        ]);
    }
}
