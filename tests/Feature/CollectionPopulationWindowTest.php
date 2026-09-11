<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionReconciliation\PopulationQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\AdmissionWindowReference;
use Tests\TestCase;

class CollectionPopulationWindowTest extends TestCase
{
    #[DataProvider('admissionPopulations')]
    public function test_admission_discovery_preserves_raw_membership_without_hydrating_overflow(int $count, ?int $state): void
    {
        $this->createCollections();
        $source = (object) ['id' => 1, 'groups_id' => 1, 'declaredfiles' => 3,
            'fromname' => 'Synthetic Poster', 'date' => '2026-01-01 09:00:00'];
        for ($id = 1; $id <= $count; $id++) {
            DB::table('collections')->insert(['id' => $id, 'groups_id' => 1, 'declaredfiles' => 3,
                'fromname' => 'Synthetic Poster', 'date' => '2026-01-01 09:00:00',
                'filecheck' => $state ?? ($id <= 250 ? 0 : 16)]);
        }
        $queries = new PopulationQuery;
        $window = $queries->sourceWindow($source);
        $reference = AdmissionWindowReference::read($window);
        $this->assertSame($count <= 256, $reference['complete']);
        foreach ([false, true] as $lock) {
            $population = DB::transaction(fn (): array => $queries->admissionPopulations(collect([$source]), $lock)[1]);
            $this->assertSame($reference['complete'], $population['complete']);
            $this->assertEquals($reference['complete'] ? $reference['rows'] : collect(), $population['rows']);
        }
    }

    public static function admissionPopulations(): iterable
    {
        foreach ([0, 1, 2, 256, 257] as $count) {
            foreach ([0, 1, 2, 3, 10, 15, 16, null] as $state) {
                yield $count.'-'.($state ?? 'mixed') => [$count, $state];
            }
        }
    }

    #[DataProvider('clocks')]
    public function test_admission_discovery_keeps_inclusive_boundaries_and_identity_predicates(string $timezone, string $day): void
    {
        $this->createCollections();
        config(['app.timezone' => $timezone]);
        $source = (object) ['id' => 1, 'groups_id' => 1, 'declaredfiles' => 3,
            'fromname' => 'Synthetic Poster', 'date' => $day.' 09:00:00'];
        $template = ['groups_id' => 1, 'declaredfiles' => 3, 'fromname' => 'Synthetic Poster', 'date' => $source->date, 'filecheck' => 2];
        foreach ([['date' => $day.' 08:00:00'], ['date' => $day.' 10:00:00'],
            ['date' => $day.' 07:59:59'], ['date' => $day.' 10:00:01'],
            ['groups_id' => 2], ['fromname' => 'Different Poster'], ['declaredfiles' => 4], ['filecheck' => 4]] as $index => $changes) {
            DB::table('collections')->insert(array_replace($template, ['id' => $index + 1], $changes));
        }
        foreach ([false, true] as $lock) {
            $result = DB::transaction(fn (): array => (new PopulationQuery)->admissionPopulations(collect([$source]), $lock));
            $this->assertTrue($result[1]['complete']);
            $this->assertSame([1, 2], $result[1]['rows']->pluck('id')->all());
        }
        foreach ([null, 'not-a-date'] as $date) {
            $source->date = $date;
            $this->assertSame([], (new PopulationQuery)->admissionPopulations(collect([$source]), false));
        }
    }

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
