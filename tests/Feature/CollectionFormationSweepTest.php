<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionSweepOutcome;
use App\Services\CollectionReconciliation\CollectionAdmission;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\Releases\CollectionSweep;
use App\Services\Releases\CollectionSweepLease;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class CollectionFormationSweepTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 1, 1)->startOfDay());
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        Schema::create('collections', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->default(1);
            $table->unsignedTinyInteger('filecheck')->default(3);
        });
        Schema::create('reconciliation_admissions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collection_id')->primary();
            $table->string('state');
            $table->dateTime('expires_at');
        });
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_full_held_page_yields_then_reaches_later_eligible_sources(): void
    {
        $this->seedSources(129);
        DB::table('collections')->insert([
            ['id' => 1000, 'groups_id' => 2, 'filecheck' => 3],
            ['id' => 2000, 'groups_id' => 1, 'filecheck' => 2],
        ]);
        foreach (range(1, 128) as $id) {
            DB::table('reconciliation_admissions')->insert([
                'collection_id' => $id, 'state' => 'admitted', 'expires_at' => now()->addMinute(),
            ]);
        }
        $sweep = new CollectionSweep;
        $first = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 128, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::BudgetYielded, $first->outcome);
        $this->assertSame(128, $first->examined);
        $this->assertSame(0, $first->deleted);
        $this->assertCursor(128, 129);

        $next = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 128, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $next->outcome);
        $this->assertSame(1, $next->examined);
        $this->assertSame(1, $next->deleted);
        $this->assertSame([129], DB::table('collections')->where('filecheck', 4)->pluck('id')->all());
        $this->assertSame(128, $this->population()->count());
        $this->assertCursor(0, 0);

        $this->travel(61)->seconds();
        $retry = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 128, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $retry->outcome);
        $this->assertSame(128, $retry->deleted);
        $this->assertSame(0, $this->population()->count());
        $this->assertSame(3, DB::table('collections')->where('id', 1000)->value('filecheck'));
        $this->assertSame(2, DB::table('collections')->where('id', 2000)->value('filecheck'));
        $this->assertCursor(0, 0);
    }

    public function test_concurrent_arrivals_wait_for_the_next_finite_pass(): void
    {
        $this->seedSources(3);
        $sweep = new CollectionSweep;
        $first = $sweep->run('formation:3', 1, function (array $ids, CollectionSweepLease $lease): int {
            $changed = $this->promoteUnowned($ids, $lease);
            DB::table('collections')->insert(['id' => 4]);

            return $changed;
        }, population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::BudgetYielded, $first->outcome);
        $this->assertCursor(2, 3);

        $next = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $next->outcome);
        $this->assertSame(1, $next->examined);
        $this->assertSame([4], $this->population()->pluck('id')->all());
        $this->assertCursor(0, 0);

        $arrival = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $arrival->outcome);
        $this->assertSame(1, $arrival->deleted);
        $this->assertSame(0, $this->population()->count());
        $this->assertCursor(0, 0);
    }

    public function test_a_population_emptied_between_pages_resets_the_cursor_for_future_arrivals(): void
    {
        $this->seedSources(3);
        $sweep = new CollectionSweep;
        $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertCursor(2, 3);
        DB::table('collections')->where('id', 3)->update(['filecheck' => 4]);

        $empty = $sweep->run('formation:3', 1, function (): never {
            $this->fail('An empty population must not invoke a mutation callback.');
        }, population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $empty->outcome);
        $this->assertSame(0, $empty->examined);
        $this->assertCursor(0, 0);

        DB::table('collections')->insert(['id' => 4]);
        $future = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(1, $future->deleted);
        $this->assertCursor(0, 0);
    }

    /** @return iterable<string, array{bool}> */
    public static function lostLeases(): iterable
    {
        yield 'expired' => [false];
        yield 'replaced' => [true];
    }

    #[DataProvider('lostLeases')]
    public function test_an_expired_or_replaced_worker_cannot_advance_the_checkpoint(bool $replace): void
    {
        $this->seedSources(3);
        $sweep = new CollectionSweep;
        $result = $sweep->run('formation:3', 1, function (array $ids, CollectionSweepLease $lease) use ($replace): int {
            $changed = $this->promoteUnowned($ids, $lease);
            if ($replace) {
                DB::table('collection_sweep_cursors')->where('scope', $lease->scope)->update([
                    'lease_token' => 'replacement-owner', 'lease_expires_at' => now()->addMinute(),
                ]);
            } else {
                $this->travel(61)->seconds();
            }

            return $changed;
        }, population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::LeaseLost, $result->outcome);
        $this->assertSame(2, $result->examined);
        $this->assertSame(2, $result->deleted);
        $cursor = DB::table('collection_sweep_cursors')->where('scope', 'formation:3:group:1')->first();
        $this->assertSame(0, (int) $cursor->last_id);
        $this->assertSame(3, (int) $cursor->high_water_id);
        $this->assertSame($replace ? 'replacement-owner' : null, $cursor->lease_token);
        if ($replace) {
            $busy = $sweep->run('formation:3', 1, $this->promoteUnowned(...), population: $this->population());
            $this->assertSame(CollectionSweepOutcome::LeaseBusy, $busy->outcome);
            $this->travel(61)->seconds();
        }
        $resumed = $sweep->run('formation:3', 1, $this->promoteUnowned(...),
            population: $this->population(), pageSize: 2, maxPages: 1);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $resumed->outcome);
        $this->assertSame(1, $resumed->deleted);
        $this->assertSame(0, $this->population()->count());
        $this->assertCursor(0, 0);
    }

    private function seedSources(int $count): void
    {
        DB::table('collections')->insert(array_map(static fn (int $id): array => ['id' => $id], range(1, $count)));
    }

    private function population(): Builder
    {
        return DB::table('collections')->where('groups_id', 1)->where('filecheck', 3);
    }

    /** @param list<int> $ids */
    private function promoteUnowned(array $ids, CollectionSweepLease $lease): int
    {
        $changed = 0;
        foreach (array_chunk($ids, CollectionAdmission::MUTATION_BATCH_SIZE) as $chunk) {
            $changed += DB::transaction(function () use ($chunk, $lease): int {
                $lease->assertOwned();
                $eligible = $this->population()->whereIn('id', $chunk);
                CollectionOwnership::exclude($eligible, populationIds: $chunk, currentRead: true);

                return $eligible->update(['filecheck' => 4]);
            });
        }

        return $changed;
    }

    private function assertCursor(int $lastId, int $highWater): void
    {
        $cursor = DB::table('collection_sweep_cursors')->where('scope', 'formation:3:group:1')->first();
        $this->assertSame($lastId, (int) $cursor->last_id);
        $this->assertSame($highWater, (int) $cursor->high_water_id);
        $this->assertNull($cursor->lease_token);
        $this->assertNull($cursor->lease_expires_at);
    }
}
