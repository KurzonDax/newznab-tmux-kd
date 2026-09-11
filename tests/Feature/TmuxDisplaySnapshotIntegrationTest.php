<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\Tmux;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

class TmuxDisplaySnapshotIntegrationTest extends TestCase
{
    public function test_cached_exact_totals_never_control_fresh_newest_release_or_collection_gate(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge();
        Cache::flush();
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, searchname TEXT, adddate TEXT, categories_id INTEGER, isrenamed INTEGER, nfostatus INTEGER, predb_id INTEGER)');
        foreach (['proc_nfo', 'proc_uid', 'proc_files', 'proc_xxx', 'proc_media_movie', 'proc_par2',
            'proc_hash16k', 'proc_srr', 'proc_crc32', 'proc_srrdb', 'nzbstatus', 'is_trusted_name'] as $column) {
            DB::statement('ALTER TABLE releases ADD '.$column.' INTEGER DEFAULT 1');
        }
        DB::statement('CREATE TABLE media_infos (releases_id INTEGER, unique_id TEXT, movie_name TEXT)');
        DB::statement('CREATE TABLE release_files (releases_id INTEGER, name TEXT, crc32 TEXT)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY)');
        DB::table('collections')->insert(['id' => 1]);
        DB::table('releases')->insert(['id' => 1, 'searchname' => 'first', 'adddate' => '2026-01-01 10:00:00', 'categories_id' => 5040, 'isrenamed' => 1, 'nfostatus' => 1, 'predb_id' => 1]);
        $this->travelTo(now()->setDate(2026, 1, 1)->setTime(12, 0));
        try {
            $monitor = new SnapshotMonitorFixture;
            $first = $monitor->display()['display_snapshot'];
            $this->assertSame(1, $first['counts']['releases']);
            $this->assertSame(1, $first['counts']['tv']);
            $this->assertSame(0, $first['counts']['processrenames']);
            DB::table('collections')->insert(['id' => 2]);
            DB::table('releases')->insert(['id' => 2, 'searchname' => 'second', 'adddate' => '2026-01-01 12:00:01', 'categories_id' => 2000, 'isrenamed' => 0, 'nfostatus' => 0, 'predb_id' => 0, 'proc_files' => 0]);
            $this->travel(1)->seconds();
            $fresh = $monitor->operational();
            $this->assertSame('second', $fresh['timers']['newOld']['newestrelname']);
            $this->assertTrue($fresh['killswitch']['coll']);
            $this->assertSame(1, $fresh['counts']['now']['collections_table']);
            $this->assertSame(0, $fresh['counts']['now']['processrenames']);
            $this->assertSame($first, (new SnapshotMonitorFixture)->display()['display_snapshot']);
            $this->travel(300)->seconds();
            $second = $monitor->display()['display_snapshot'];
            $this->assertSame(2, $second['counts']['releases']);
            $this->assertSame(1, $second['counts']['movies']);
            $this->assertSame(1, $second['counts']['processrenames']);
            $this->assertGreaterThan($first['observed_at'], $second['observed_at']);
            DB::statement('DROP TABLE media_infos');
            DB::table('releases')->insert(['id' => 3, 'searchname' => 'third', 'adddate' => '2026-01-01 12:00:02', 'categories_id' => 2000, 'isrenamed' => 1, 'nfostatus' => 0, 'predb_id' => 0]);
            $this->travel(300)->seconds();
            $third = $monitor->display()['display_snapshot'];
            $this->assertSame(3, $third['counts']['releases'], 'Naming evidence failure must not freeze unrelated display totals.');
            $this->assertSame(1, $third['counts']['processrenames']);
            DB::statement('DROP TABLE releases');
            $this->travel(300)->seconds();
            $this->assertSame($third, $monitor->display()['display_snapshot']);
            Cache::flush();
            $this->assertNull((new SnapshotMonitorFixture)->display()['display_snapshot']);
        } finally {
            $this->travelBack();
        }
    }
}

class SnapshotMonitorFixture extends TmuxMonitorService
{
    public function __construct()
    {
        $this->tmux = (new ReflectionClass(Tmux::class))->newInstanceWithoutConstructor();
        $this->runVar = ['counts' => ['now' => []], 'settings' => ['collections_kill' => 1]];
    }

    /** @return array<string, mixed> */
    public function display(): array
    {
        $this->refreshSlowStatistics();

        return $this->runVar;
    }

    /** @return array<string, mixed> */
    public function operational(): array
    {
        $this->refreshOperationalReleaseStatistics();
        $this->setKillswitches();

        return $this->runVar;
    }

    protected function getTableCounts(): void {}
}
