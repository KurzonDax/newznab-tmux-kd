<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Models\Release;
use App\Services\ReleaseRepair\RecoveryLease;
use App\Services\Releases\ReleaseClaimWindow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class ReleaseClaimWindowTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private int $schemaQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        ReleaseClaimWindow::flushColumnCache();

        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'pragma_table') || str_contains($query->sql, 'sqlite_master')) {
                $this->schemaQueries++;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ReleaseClaimWindow::flushColumnCache();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function timeoutSettings(): array
    {
        return [
            'missing setting' => [null, 300],
            'empty setting' => ['', 300],
            'floor applies at 120' => ['120', 300],
            'twice the timeout above the floor' => ['200', 400],
        ];
    }

    #[DataProvider('timeoutSettings')]
    public function test_ttl_is_twice_the_release_processing_timeout_with_a_300_second_floor(?string $setting, int $expected): void
    {
        if ($setting !== null) {
            DB::table('settings')->insert(['name' => 'releaseprocessingtimeout', 'value' => $setting]);
        }

        $this->assertSame($expected, ReleaseClaimWindow::ttlSeconds());
    }

    public function test_stale_before_is_now_minus_the_ttl(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00 UTC');
        DB::table('settings')->insert(['name' => 'releaseprocessingtimeout', 'value' => '200']);

        $this->assertSame('2026-07-13 11:53:20', ReleaseClaimWindow::staleBefore()->format('Y-m-d H:i:s'));
    }

    public function test_apply_free_keeps_unclaimed_and_strictly_stale_claims_only(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00 UTC');
        $this->createReleases(['id', 'additional_pp_claimed_at']);
        DB::table('releases')->insert([
            ['id' => 1, 'additional_pp_claimed_at' => null],
            ['id' => 2, 'additional_pp_claimed_at' => ReleaseClaimWindow::staleBefore()->subSecond()],
            ['id' => 3, 'additional_pp_claimed_at' => ReleaseClaimWindow::staleBefore()],
            ['id' => 4, 'additional_pp_claimed_at' => now()],
        ]);

        $free = ReleaseClaimWindow::applyFree(Release::query(), 'additional_pp_claimed_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertSame([1, 2], $free);
    }

    public function test_apply_free_groups_its_predicate_so_it_does_not_widen_an_or(): void
    {
        Carbon::setTestNow('2026-07-13 12:00:00 UTC');
        $this->createReleases(['id', 'additional_pp_claimed_at']);
        DB::table('releases')->insert([
            ['id' => 1, 'additional_pp_claimed_at' => ReleaseClaimWindow::staleBefore()->subSecond()],
            ['id' => 2, 'additional_pp_claimed_at' => null],
        ]);

        $free = ReleaseClaimWindow::applyFree(Release::query()->where('id', 2), 'additional_pp_claimed_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertSame([2], $free);
    }

    public function test_releases_has_column_reads_the_schema_once_per_connection(): void
    {
        $this->createReleases(['id', 'recovery_claimed_at']);
        $this->schemaQueries = 0;

        $this->assertTrue(ReleaseClaimWindow::releasesHasColumn('recovery_claimed_at'));
        $afterFirst = $this->schemaQueries;
        $this->assertTrue(ReleaseClaimWindow::releasesHasColumn('RECOVERY_CLAIMED_AT'));
        $this->assertFalse(ReleaseClaimWindow::releasesHasColumn('recovery_claim_token'));

        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame($afterFirst, $this->schemaQueries);
    }

    public function test_releases_has_column_reports_no_columns_for_a_missing_releases_table(): void
    {
        $this->assertFalse(Schema::hasTable('releases'));

        $this->assertFalse(ReleaseClaimWindow::releasesHasColumn('id'));
    }

    public function test_releases_has_column_reads_the_schema_again_after_a_flush(): void
    {
        $this->createReleases(['id']);
        $this->assertFalse(ReleaseClaimWindow::releasesHasColumn('recovery_claimed_at'));

        Schema::table('releases', static fn (Blueprint $table) => $table->timestamp('recovery_claimed_at')->nullable());
        $this->assertFalse(ReleaseClaimWindow::releasesHasColumn('recovery_claimed_at'));

        ReleaseClaimWindow::flushColumnCache();
        $this->schemaQueries = 0;

        $this->assertTrue(ReleaseClaimWindow::releasesHasColumn('recovery_claimed_at'));
        $this->assertGreaterThan(0, $this->schemaQueries);
    }

    public function test_recovery_lease_availability_sends_no_schema_query_after_the_first_call(): void
    {
        $this->createReleases(['id', 'recovery_claimed_at']);
        RecoveryLease::applyAvailable(Release::query());
        $this->schemaQueries = 0;

        RecoveryLease::applyAvailable(Release::query());

        $this->assertSame(0, $this->schemaQueries);
    }

    /**
     * @param  list<string>  $columns
     */
    private function createReleases(array $columns): void
    {
        ProductionTables::fromAuthority()->create('releases', $columns);
    }
}
