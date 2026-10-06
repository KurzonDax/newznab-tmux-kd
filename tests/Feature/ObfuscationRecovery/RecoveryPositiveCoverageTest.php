<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryPositiveCoverage;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class RecoveryPositiveCoverageTest extends TestCase
{
    use IsolatedSqliteDatabase;

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
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @param list<array{string,string,int,int}> $ranges
     * @param  list<array{int,int}>  $expected
     */
    #[DataProvider('intersectionCases')]
    public function test_intersecting_returns_only_ranges_touching_the_window(array $ranges, int $first, int $last, array $expected): void
    {
        foreach ($ranges as [$kind, $direction, $from, $to]) {
            $this->range($kind, $direction, $from, $to);
        }

        $this->assertSame($expected, $this->intersecting($first, $last, 101));
    }

    public static function intersectionCases(): array
    {
        return [
            'no predecessor' => [[['captured', 'Head', 100, 120], ['captured', 'Head', 130, 140]], 90, 125, [[100, 120]]],
            'predecessor ending before the window' => [[['captured', 'Head', 10, 99], ['captured', 'Head', 105, 110]], 100, 200, [[105, 110]]],
            'predecessor ending at the widened first' => [[['captured', 'Head', 10, 99], ['captured', 'Head', 105, 110]], 99, 200, [[10, 99], [105, 110]]],
            'predecessor overlapping first' => [[['captured', 'Head', 1, 5], ['captured', 'Head', 10, 150], ['captured', 'Head', 160, 170]], 100, 200, [[10, 150], [160, 170]]],
            'ranges inside the window' => [[['captured', 'Head', 110, 120], ['captured', 'Head', 130, 140], ['captured', 'Head', 300, 400]], 100, 200, [[110, 120], [130, 140]]],
            'range starting exactly at last' => [[['captured', 'Head', 200, 250], ['captured', 'Head', 251, 260]], 100, 200, [[200, 250]]],
            'other direction or kind excluded' => [[['captured', 'Repair', 50, 150], ['retained', 'Head', 50, 150], ['captured', 'Tail', 120, 130],
                ['retained', 'Head', 160, 170], ['captured', 'Head', 180, 190]], 100, 200, [[180, 190]]],
        ];
    }

    public function test_intersecting_slices_to_the_limit_in_article_order(): void
    {
        foreach ([50, 80, 70, 60, 90] as $first) {
            $this->range('captured', 'Head', $first, $first + 5);
        }
        $this->range('captured', 'Head', 10, 45);

        $this->assertSame([[10, 45], [50, 55], [60, 65]], $this->intersecting(45, 100, 3));
        $this->assertSame([[50, 55], [60, 65]], $this->intersecting(46, 100, 2));
    }

    /** @return list<array{int,int}> */
    private function intersecting(int $first, int $last, int $limit): array
    {
        return (new RecoveryPositiveCoverage)->intersecting(DB::connection(), RecoveryPositiveCoverage::scope('epoch', 1, 1),
            'captured', 'Head', $first, $last, $limit)
            ->map(static fn (object $row): array => [(int) $row->first_article, (int) $row->last_article])->values()->all();
    }

    private function range(string $kind, string $direction, int $first, int $last): void
    {
        DB::table('obfuscation_recovery_coverage')->insert([
            'scope_digest' => RecoveryPositiveCoverage::scope('epoch', 1, 1), 'source_epoch' => 'epoch', 'groups_id' => 1,
            'capture_generation' => 1, 'kind' => $kind, 'direction' => $direction, 'first_article' => $first, 'last_article' => $last,
        ]);
    }
}
