<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class TvEpisodeRevisitMigrationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Carbon::setTestNow('2026-08-25 12:00:00');
        config(['nntmux.tv_episode_revisit_window_days' => 14]);

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->dateTime('postdate');
            $table->integer('tv_episodes_id')->default(0);
        });

        DB::table('releases')->insert([
            ['id' => 1, 'postdate' => now()->subDays(2), 'tv_episodes_id' => -4],
            ['id' => 2, 'postdate' => now()->subDays(20), 'tv_episodes_id' => -4],
            ['id' => 3, 'postdate' => now()->subDays(2), 'tv_episodes_id' => -3],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function migration_adds_pacing_state_and_splits_legacy_minus_four_rows_by_postdate(): void
    {
        $paths = glob(database_path('migrations/*_add_tv_episode_revisit_state_to_releases_table.php')) ?: [];

        $this->assertCount(1, $paths);
        $migration = require $paths[0];
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('releases', 'tv_episode_lookup_attempted_at'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('tv_episodes_id'));
        $this->assertSame(-6, (int) DB::table('releases')->where('id', 2)->value('tv_episodes_id'));
        $this->assertSame(-3, (int) DB::table('releases')->where('id', 3)->value('tv_episodes_id'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('releases', 'tv_episode_lookup_attempted_at'));
    }
}
