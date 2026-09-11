<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\NameFixing\NameFixingQueryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CandidateQueryPlans;
use Tests\TestCase;

final class NamingCandidateScaleMariaDbTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $database = getenv('CBP_INTEGRATION_DB_DATABASE');
        if ($database === false || $database === '') {
            return parent::createApplication();
        }
        if ($database !== 'cbp_integration') {
            throw new \RuntimeException('Collection scale tests require the isolated cbp_integration database.');
        }

        foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        $this->setEnvironmentValue('DB_CONNECTION', 'mariadb');
        $this->setEnvironmentValue('DB_DATABASE', $database);
        $this->setEnvironmentValue('DB_HOST', 'mariadb');
        $this->setEnvironmentValue('DB_USERNAME', (string) getenv('CBP_INTEGRATION_DB_USERNAME'));
        $this->setEnvironmentValue('DB_PASSWORD', (string) getenv('CBP_INTEGRATION_DB_PASSWORD'));

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Isolated MariaDB integration test.');
        }
        if (DB::connection()->getDatabaseName() !== 'cbp_integration') {
            throw new \RuntimeException('Refusing a non-test database.');
        }
        DB::setTablePrefix('naming_'.getmypid().'_');
        DB::statement('CREATE TABLE '.DB::getTablePrefix().'releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            searchname VARCHAR(255) NOT NULL,
            fromname VARCHAR(255) NOT NULL,
            guid VARCHAR(64) NOT NULL,
            leftguid VARCHAR(1) NOT NULL,
            groups_id INTEGER NOT NULL,
            categories_id INTEGER NOT NULL,
            size INTEGER NOT NULL,
            completion INTEGER NOT NULL DEFAULT 0,
            adddate DATETIME NOT NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            predb_id INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            passwordstatus INTEGER NOT NULL DEFAULT 0,
            is_trusted_name INTEGER NOT NULL DEFAULT 0
        )');

        DB::statement('CREATE TABLE '.DB::getTablePrefix().'release_files (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            releases_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            crc32 VARCHAR(8) NULL,
            size INTEGER NOT NULL DEFAULT 0, KEY release_files_release (releases_id)
        )');

        DB::statement('CREATE TABLE '.DB::getTablePrefix().'media_infos (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            releases_id INTEGER NOT NULL,
            movie_name VARCHAR(255),
            unique_id VARCHAR(255), KEY media_infos_release (releases_id)
        )');
        config(['nntmux_srrdb.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && str_starts_with(DB::getTablePrefix(), 'naming_')) {
            foreach (['releases', 'media_infos', 'release_files'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setTablePrefix('');
        }
        parent::tearDown();
        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_generated_work_flags_follow_inserts_and_every_source_transition(): void
    {
        $migration = require database_path('migrations/2026_09_10_231728_add_name_direct_work_index_to_releases.php');
        $migration->up();
        $terminal = array_fill_keys(['proc_nfo', 'proc_files', 'proc_par2', 'proc_srr', 'proc_hash16k', 'proc_crc32',
            'proc_uid', 'proc_media_movie', 'proc_xxx', 'proc_srrdb'], 1);
        DB::table('releases')->insert($terminal + ['id' => 1, 'name' => 'fixture', 'searchname' => 'fixture',
            'fromname' => 'neutral', 'guid' => sha1('transitions'), 'leftguid' => 'a', 'groups_id' => 1,
            'categories_id' => 7000, 'size' => 100, 'adddate' => now(), 'nfostatus' => 1, 'nzbstatus' => 1]);
        $this->assertSame(0, (int) DB::table('releases')->value('name_direct_work_pending'));
        foreach (array_keys($terminal) as $flag) {
            DB::table('releases')->update($terminal);
            DB::table('releases')->update([$flag => 0]);
            $evidence = in_array($flag, ['proc_uid', 'proc_media_movie', 'proc_xxx', 'proc_srrdb'], true);
            $this->assertSame((int) ! $evidence, (int) DB::table('releases')->value('name_direct_work_pending'), $flag);
            $this->assertSame((int) $evidence, (int) DB::table('releases')->value('name_evidence_work_pending'), $flag);
        }
        foreach (['nfostatus' => 'proc_nfo', 'nzbstatus' => 'proc_par2'] as $status => $flag) {
            DB::table('releases')->update($terminal);
            DB::table('releases')->update([$flag => 0, $status => -1]);
            $this->assertSame(0, (int) DB::table('releases')->value('name_direct_work_pending'));
            DB::table('releases')->update([$status => 1]);
            $this->assertSame(1, (int) DB::table('releases')->value('name_direct_work_pending'));
        }
        $migration->down();
        $this->assertFalse(Schema::hasColumn('releases', 'name_direct_work_pending'));
        $this->assertFalse(Schema::hasColumn('releases', 'name_evidence_work_pending'));
    }

    public function test_retained_evidence_work_stays_bounded_across_all_buckets(): void
    {
        $migration = require database_path('migrations/2026_09_10_231728_add_name_direct_work_index_to_releases.php');
        $measurements = [];
        foreach ([100000, 1000000] as $size) {
            foreach (['media_infos', 'release_files', 'releases'] as $table) {
                DB::table($table)->delete();
            }
            $r = DB::getTablePrefix().'releases';
            $m = DB::getTablePrefix().'media_infos';
            $f = DB::getTablePrefix().'release_files';
            DB::statement("INSERT INTO `$r` (id,name,searchname,fromname,guid,leftguid,groups_id,categories_id,size,adddate,
                nfostatus,nzbstatus,isrenamed,predb_id,proc_nfo,proc_files,proc_par2,proc_srr,proc_hash16k,proc_crc32,
                proc_uid,proc_media_movie,proc_xxx,proc_srrdb)
                SELECT seq,'fixture','fixture','neutral',SHA1(CONCAT('release:',seq)),SUBSTRING('0123456789abcdef',MOD(seq,16)+1,1),
                1,7000,100,'2026-01-01',1,1,1,0,1,1,1,1,1,1,1,1,1,1 FROM seq_1_to_$size");
            DB::statement("INSERT INTO `$m` (releases_id,unique_id,movie_name) SELECT id,CONCAT('uid:',id),'neutral title' FROM `$r`");
            DB::statement("INSERT INTO `$f` (releases_id,name,crc32) SELECT id,'SDPORN.fixture.rar',LPAD(HEX(id),8,'0') FROM `$r`");
            DB::statement("INSERT INTO `$f` (releases_id,name,crc32) SELECT id,'ordinary.rar',LPAD(HEX(id),8,'0') FROM `$r`");
            $writeBefore = $this->measureReleaseWrites();
            $start = microtime(true);
            $migration->up();
            $build = microtime(true) - $start;
            $writeAfter = $this->measureReleaseWrites();
            $indexBytes = (int) DB::selectOne('SELECT INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', ['cbp_integration', $r])->bytes;
            DB::statement("ANALYZE TABLE `$r`, `$m`, `$f`");
            foreach ([1, 0] as $renamed) {
                DB::table('releases')->update(['isrenamed' => $renamed]);
                $before = $this->reads();
                DB::enableQueryLog();
                $start = microtime(true);
                $service = new NameFixingQueryService;
                $this->assertSame(0, $service->standardCandidateCount());
                foreach (str_split('0123456789abcdef') as $bucket) {
                    $this->assertSame([], $service->standardCandidateBatch($bucket, 100));
                }
                $seconds = microtime(true) - $start;
                $sql = DB::getQueryLog();
                DB::disableQueryLog();
                DB::flushQueryLog();
                $reads = $this->reads() - $before;
                $plans = CandidateQueryPlans::inspect($sql);
                $this->assertLessThan(10000, max(array_column($plans, 'rows_times_loops')));
                fwrite(STDERR, 'CANDIDATE_PLANS='.json_encode($plans, JSON_THROW_ON_ERROR).PHP_EOL);
                $measurements[$size][$renamed] = $reads;
                fwrite(STDERR, json_encode(['size' => $size, 'media_rows' => $size, 'file_rows' => 2 * $size,
                    'renamed' => $renamed, 'reads' => $reads, 'seconds' => $seconds, 'build_seconds' => $build,
                    'sql_count' => count($sql), 'index_bytes' => $indexBytes, 'write_p95_before' => $writeBefore, 'write_p95_after' => $writeAfter]).PHP_EOL);
            }
            foreach ([0, 160, 4096] as $pending) {
                $this->measurePendingCalls($size, $pending);
            }
            $migration->down();
            $this->assertFalse(Schema::hasColumn('releases', 'name_direct_work_pending'));
            $this->assertFalse(Schema::hasColumn('releases', 'name_evidence_work_pending'));
        }
        foreach ([0, 1] as $renamed) {
            $this->assertLessThanOrEqual($measurements[100000][$renamed] * 2 + 1000, $measurements[1000000][$renamed]);
        }
    }

    private function measureReleaseWrites(): float
    {
        $times = [];
        foreach (range(0, 19) as $sample) {
            $start = microtime(true);
            DB::table('releases')->whereBetween('id', [1, 1000])->update([
                'proc_files' => $sample % 2, 'proc_uid' => $sample % 2,
            ]);
            $times[] = microtime(true) - $start;
        }
        sort($times);

        return $times[18];
    }

    private function measurePendingCalls(int $size, int $pending): void
    {
        DB::table('media_infos')->where('releases_id', '>', $size)->delete();
        DB::table('release_files')->where('releases_id', '>', $size)->delete();
        DB::table('releases')->where('id', '>', $size)->delete();
        $flags = ['proc_nfo', 'proc_files', 'proc_par2', 'proc_srr', 'proc_hash16k', 'proc_crc32',
            'proc_uid', 'proc_media_movie', 'proc_xxx', 'proc_srrdb'];
        $base = (array) DB::table('releases')->where('id', 1)->first();
        unset($base['name_direct_work_pending'], $base['name_evidence_work_pending']);
        foreach ($pending === 0 ? [] : range(1, $pending) as $offset) {
            $id = $size + $offset;
            $row = array_replace($base, ['id' => $id, 'guid' => sha1('pending:'.$offset),
                'leftguid' => dechex($offset % 16), 'isrenamed' => 0, $flags[($offset - 1) % 10] => 0]);
            DB::table('releases')->insert($row);
            DB::table('media_infos')->insert(['releases_id' => $id, 'unique_id' => 'pending uid', 'movie_name' => 'pending title']);
            DB::table('release_files')->insert(['releases_id' => $id, 'name' => 'SDPORN.pending.rar', 'crc32' => sprintf('%08x', $offset)]);
            $actual = DB::table('releases')->where('id', $id)->first();
            $this->assertSame(($offset - 1) % 10 < 6 ? 1 : 0, (int) $actual->name_direct_work_pending);
            $this->assertSame(($offset - 1) % 10 >= 6 ? 1 : 0, (int) $actual->name_evidence_work_pending);
        }
        $service = new NameFixingQueryService;
        $samples = [];
        $byCall = [];
        $before = $this->reads();
        DB::enableQueryLog();
        for ($sample = 0; $sample < 20; $sample++) {
            $start = microtime(true);
            $actualCount = $service->standardCandidateCount();
            $samples[] = $byCall['count'][] = microtime(true) - $start;
            $this->assertSame($pending, $actualCount);
            foreach (str_split('0123456789abcdef') as $bucket) {
                $start = microtime(true);
                $actual = array_map(static fn ($row): int => (int) $row->id, $service->standardCandidateBatch($bucket, 100));
                $samples[] = $byCall[$bucket][] = microtime(true) - $start;
                $expected = array_values(array_filter($pending === 0 ? [] : range($size + $pending, $size + 1), static fn (int $id): bool => dechex(($id - $size) % 16) === $bucket));
                $this->assertSame(array_slice($expected, 0, 100), $actual);
            }
        }
        $reads = $this->reads() - $before;
        $sql = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $plans = CandidateQueryPlans::inspect($sql);
        $this->assertLessThan(10000, max(array_column($plans, 'rows_times_loops')));
        fwrite(STDERR, 'PENDING_NAMING_PLANS='.json_encode($plans, JSON_THROW_ON_ERROR).PHP_EOL);
        $p95ByCall = [];
        foreach ($byCall as $call => $durations) {
            sort($durations);
            $this->assertCount(20, $durations);
            $p95ByCall[$call] = $durations[18];
            $this->assertLessThan(1.0, $p95ByCall[$call], (string) $call);
        }
        sort($samples);
        $p95 = $samples[(int) ceil(count($samples) * 0.95) - 1];
        fwrite(STDERR, json_encode(['pending_size' => $size, 'pending' => $pending, 'samples' => count($samples),
            'p95_seconds' => $p95, 'p95_by_call' => $p95ByCall, 'reads' => $reads]).PHP_EOL);
        $this->assertLessThan(1.0, $p95);
    }

    private function reads(): int
    {
        return array_sum(array_map(static fn ($row): int => (int) $row->Value,
            DB::select("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd','Handler_read_rnd_next')")));
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
