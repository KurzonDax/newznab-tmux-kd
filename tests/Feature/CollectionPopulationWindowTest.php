<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionReconciliation\PopulationQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollectionPopulationWindowTest extends TestCase
{
    public function test_read_and_lock_windows_share_one_raw_population_allowance(): void
    {
        $this->createCollections();
        for ($id = 1; $id <= 260; $id++) {
            DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'declaredfiles' => 5,
                'fromname' => 'Synthetic', 'filecheck' => $id <= 250 ? 0 : 1, 'date' => '2026-01-10 08:00:00']);
        }
        $query = new PopulationQuery;
        $window = $query->sourceWindow(DB::table('collections')->first());
        foreach (['readWindow', 'lockWindow'] as $method) {
            $population = DB::transaction(fn (): array => $query->$method($window));
            $this->assertFalse($population['complete']);
            $this->assertSame(range(1, 257), $population['rows']->pluck('id')->all());
        }
        DB::table('collections')->where('id', '>', 256)->delete();
        foreach (['readWindow', 'lockWindow'] as $method) {
            $population = DB::transaction(fn (): array => $query->$method($window));
            $this->assertTrue($population['complete']);
            $this->assertSame(range(1, 256), $population['rows']->pluck('id')->all());
        }
    }

    public function test_historical_discovery_converts_epochs_and_counts_all_posters_before_residual_states(): void
    {
        $this->createCollections();
        config(['app.timezone' => 'America/Chicago']);
        for ($id = 1; $id <= 257; $id++) {
            DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'declaredfiles' => 5,
                'fromname' => 'Synthetic '.$id, 'filecheck' => $id === 1 ? 0 : 4, 'date' => '2026-01-10 08:00:00']);
        }
        // 14:00 UTC is 08:00 in Chicago in January.
        $epoch = 1768053600;
        $population = (new PopulationQuery)->readHistoricalWindow(1, 5, $epoch);
        $this->assertFalse($population['complete']);
        $this->assertCount(257, $population['rows']);
        DB::table('collections')->where('id', 257)->delete();
        $population = (new PopulationQuery)->readHistoricalWindow(1, 5, $epoch);
        $this->assertTrue($population['complete']);
        $this->assertCount(256, $population['rows']);
    }

    private function createCollections(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        Schema::create('collections', static function (Blueprint $table): void {
            $table->id();
            $table->integer('groups_id');
            $table->integer('declaredfiles');
            $table->string('fromname');
            $table->integer('filecheck');
            $table->dateTime('date');
        });
    }

    #[DataProvider('clocks')]
    public function test_source_window_uses_the_application_clock(string $timezone, string $day): void
    {
        config(['app.timezone' => $timezone]);
        $previous = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $window = (new PopulationQuery)->sourceWindow((object) [
                'date' => $day.' 08:00:00', 'groups_id' => 1, 'fromname' => 'Synthetic', 'declaredfiles' => 5,
            ]);
            $this->assertSame($day.' 07:00:00', $window['from']);
            $this->assertSame($day.' 09:00:00', $window['until']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public static function clocks(): array
    {
        return [['UTC', '2026-01-10'], ['UTC', '2026-07-10'],
            ['America/Chicago', '2026-01-10'], ['America/Chicago', '2026-07-10']];
    }
}
