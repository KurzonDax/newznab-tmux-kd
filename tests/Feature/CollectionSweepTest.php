<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionSweepOutcome;
use App\Services\Releases\CollectionSweep;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CollectionSweepTest extends TestCase
{
    public function test_no_match_pages_advance_and_resume_with_a_twenty_page_bound(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        Schema::create('collections', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->index();
        });
        for ($first = 1; $first <= 21001; $first += 1000) {
            $rows = [];
            for ($id = $first; $id < min(21002, $first + 1000); $id++) {
                $rows[] = ['id' => $id, 'groups_id' => 1];
            }
            DB::table('collections')->insert($rows);
        }
        $examined = [];
        $page = static function (array $ids) use (&$examined): int {
            $examined = [...$examined, ...$ids];

            return 0;
        };
        $sweep = new CollectionSweep;
        $result = $sweep->run('orphan', null, $page);
        $this->assertSame(CollectionSweepOutcome::BudgetYielded, $result->outcome);
        $this->assertSame(20000, $result->examined);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(range(1, 20000), $examined);
        DB::table('collection_sweep_cursors')->update(['lease_token' => 'busy', 'lease_expires_at' => now()->addMinute()]);
        $busy = $sweep->run('orphan', null, $page);
        $this->assertSame(CollectionSweepOutcome::LeaseBusy, $busy->outcome);
        $this->assertSame(0, $busy->examined);
        DB::table('collection_sweep_cursors')->update(['lease_token' => null, 'lease_expires_at' => null]);
        $examined = [];
        $result = $sweep->run('orphan', null, $page);
        $this->assertSame(CollectionSweepOutcome::Exhausted, $result->outcome);
        $this->assertSame(1001, $result->examined);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(range(20001, 21001), $examined);
        $examined = [];
        $sweep->run('orphan', null, $page);
        $this->assertSame(range(1, 20000), $examined);
    }
}
