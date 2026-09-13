<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryFrontiers;
use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use Illuminate\Support\Facades\DB;

trait ChecksFrontierMigration
{
    public function test_additive_frontier_migration_preserves_legacy_facts_until_verified_replacement(): void
    {
        $migration = require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php');
        $migration->down();
        $scope = RecoveryPositiveCoverage::scope('fixture', 1, 1);
        DB::table('obfuscation_recovery_frontiers')->insert(['scope_digest' => $scope, 'article_number' => 1,
            'postdate' => '2026-09-07 09:50:00', 'head_observed' => true]);
        DB::table('obfuscation_recovery_frontier_conflicts')->insert(['identity' => hash('sha256', 'legacy-migration'),
            'scope_digest' => $scope, 'kind' => 'unknown', 'first_article' => 1, 'last_article' => 10]);
        $migration->up();
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_frontiers')->value('evidence_version'));
        $this->assertSame('2026-09-07 09:50:00', DB::table('obfuscation_recovery_frontiers')->value('postdate'));
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $this->assertSame(0, (int) DB::table('obfuscation_recovery_frontier_conflicts')->value('span_bucket'));
        $scan = (object) ['source_epoch' => 'fixture', 'groups_id' => 1, 'capture_generation' => 1, 'direction' => 'Head',
            'requested_first' => 1, 'requested_last' => 10, 'evidence_version' => RecoveryFrontiers::VERSION,
            'date_points' => '[[1,"2026-09-07 09:50:00"]]'];
        DB::transaction(fn () => (new RecoveryFrontiers)->record(DB::connection(), $scan));
        $this->assertSame(0, DB::table('obfuscation_recovery_frontier_conflicts')->count());
        $this->assertSame(1, DB::table('obfuscation_recovery_frontier_ranges')->count());
    }
}
