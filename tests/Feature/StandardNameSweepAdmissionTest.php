<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\Tmux\Tmux;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The standard name sweep admits on per-source readiness only.
 *
 * A release is admissible when it is unrenamed, carries no PreDB identity, and
 * at least one evidence source is both ready and unconsumed. No cross-source
 * gate (NFO status, password status) and no category restriction may hide one
 * source's ready evidence behind another source's state.
 */
class StandardNameSweepAdmissionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * Every per-source status column the sweep admits on, and the value that
     * marks that source unconsumed.
     */
    private const CONSUMED = [
        'proc_nfo' => 1,
        'proc_uid' => 1,
        'proc_files' => 1,
        'proc_xxx' => 1,
        'proc_media_movie' => 1,
        'proc_par2' => 1,
        'proc_hash16k' => 1,
        'proc_srr' => 1,
        'proc_crc32' => 1,
        'proc_srrdb' => 1,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        config(['nntmux_srrdb.enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('terminalNfoStatuses')]
    public function ready_non_nfo_evidence_is_admitted_whatever_the_nfo_status_is(int $nfostatus): void
    {
        $this->insertRelease(1, ['nfostatus' => $nfostatus, 'proc_files' => 0]);

        $this->assertSame([1], $this->candidateIds());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function terminalNfoStatuses(): array
    {
        return [
            'nfo pending' => [-1],
            'nfo retries exhausted' => [-9],
            'nfo permanently unavailable' => [-10],
            'nfo found' => [1],
            'nfo absent' => [0],
        ];
    }

    #[Test]
    public function ready_evidence_is_admitted_while_password_inspection_is_pending(): void
    {
        $this->insertRelease(1, ['passwordstatus' => -1, 'proc_uid' => 0]);

        $this->assertSame([1], $this->candidateIds());
    }

    #[Test]
    public function an_unrenamed_typed_root_release_with_unconsumed_evidence_is_admitted(): void
    {
        $this->insertRelease(1, ['categories_id' => Category::OTHER_MISC, 'proc_files' => 0]);
        $this->insertRelease(2, ['categories_id' => Category::MUSIC_LOSSLESS, 'proc_files' => 0]);
        $this->insertRelease(3, ['categories_id' => Category::MOVIE_HD, 'proc_crc32' => 0]);

        // Newest first, as the sweep orders.
        $this->assertSame([3, 2, 1], $this->candidateIds());
    }

    #[Test]
    public function the_nfo_term_keeps_its_own_readiness(): void
    {
        // proc_nfo is unconsumed but no NFO has been found, so the NFO term is
        // not ready and nothing else is unconsumed.
        $this->insertRelease(1, ['nfostatus' => 0, 'proc_nfo' => 0]);

        $this->assertSame([], $this->candidateIds());

        DB::table('releases')->where('id', 1)->update(['nfostatus' => 1]);

        $this->assertSame([1], $this->candidateIds());
    }

    #[Test]
    public function the_par2_term_keeps_its_own_nzb_readiness(): void
    {
        $this->insertRelease(1, [
            'proc_par2' => 0,
            'nzbstatus' => 0,
        ]);

        $this->assertSame([], $this->candidateIds());

        DB::table('releases')->where('id', 1)->update(['nzbstatus' => 1]);

        $this->assertSame([1], $this->candidateIds());
    }

    #[Test]
    public function a_release_with_every_source_consumed_is_not_admitted(): void
    {
        $this->insertRelease(1, ['nfostatus' => 1]);

        $this->assertSame([], $this->candidateIds());
    }

    #[Test]
    public function new_source_flags_do_not_admit_a_release_before_their_evidence_exists(): void
    {
        $this->insertRelease(1, ['proc_xxx' => 0]);
        $this->insertRelease(2, ['proc_media_movie' => 0]);

        $this->assertSame([], $this->candidateIds());
    }

    #[Test]
    public function renamed_and_predb_matched_releases_are_never_admitted(): void
    {
        $this->insertRelease(1, ['isrenamed' => 1, 'proc_files' => 0]);
        $this->insertRelease(2, ['predb_id' => 42, 'proc_files' => 0]);

        $this->assertSame([], $this->candidateIds());
    }

    #[Test]
    public function a_release_whose_only_unconsumed_source_is_srrdb_is_admitted(): void
    {
        config(['nntmux_srrdb.enabled' => true]);
        $this->insertRelease(1, ['nfostatus' => 1, 'proc_srrdb' => 0]);
        $this->insertReleaseFile(1, 'Some.Release-GRP.rar', 'A1B2C3D4');

        $this->assertSame([1], $this->candidateIds());
    }

    #[Test]
    public function the_srrdb_term_is_absent_while_the_source_is_disabled(): void
    {
        config(['nntmux_srrdb.enabled' => false]);
        $this->insertRelease(1, ['nfostatus' => 1, 'proc_srrdb' => 0]);
        $this->insertReleaseFile(1, 'Some.Release-GRP.rar', 'A1B2C3D4');

        $this->assertSame([], $this->candidateIds());
    }

    #[Test]
    public function the_srrdb_term_honours_the_workers_trust_and_evidence_gates(): void
    {
        config(['nntmux_srrdb.enabled' => true]);

        // Trusted name: the SRRDB worker skips it, so the sweep must not admit it.
        $this->insertRelease(1, ['nfostatus' => 1, 'proc_srrdb' => 0, 'is_trusted_name' => 1]);
        $this->insertReleaseFile(1, 'Some.Release-GRP.rar', 'A1B2C3D4');

        // No archive CRC to query with.
        $this->insertRelease(2, ['nfostatus' => 1, 'proc_srrdb' => 0]);
        $this->insertReleaseFile(2, 'Other.Release-GRP.rar', '');

        $this->assertSame([], $this->candidateIds());
    }

    #[Test]
    #[DataProvider('sourceColumns')]
    public function the_monitor_count_and_the_candidate_batch_agree_for_every_source(string $column): void
    {
        config(['nntmux_srrdb.enabled' => true]);

        $queries = new NameFixingQueryService;

        $this->assertSame(0, $queries->standardCandidateCount());
        $this->assertSame([], $this->candidateIds());

        $overrides = [$column => 0];
        if ($column === 'proc_nfo') {
            $overrides['nfostatus'] = 1;
        }

        $this->insertRelease(1, $overrides);
        $fileName = $column === 'proc_xxx' ? 'Some.SDPORN.Release-GRP.rar' : 'Some.Release-GRP.rar';
        $this->insertReleaseFile(1, $fileName, 'A1B2C3D4');
        if ($column === 'proc_media_movie') {
            $this->insertMediaInfo(1);
        }

        $this->assertSame(1, $queries->standardCandidateCount());
        $this->assertSame([1], $this->candidateIds());

        DB::table('releases')->where('id', 1)->update([$column => self::CONSUMED[$column]]);

        $this->assertSame(0, $queries->standardCandidateCount());
        $this->assertSame([], $this->candidateIds());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sourceColumns(): array
    {
        return [
            'nfo' => ['proc_nfo'],
            'uid' => ['proc_uid'],
            'files' => ['proc_files'],
            'xxx' => ['proc_xxx'],
            'media movie' => ['proc_media_movie'],
            'par2' => ['proc_par2'],
            'hash16k' => ['proc_hash16k'],
            'srr' => ['proc_srr'],
            'crc32' => ['proc_crc32'],
            'srrdb' => ['proc_srrdb'],
        ];
    }

    #[Test]
    public function the_tmux_stats_query_no_longer_restates_the_sweep_predicate(): void
    {
        $monitorQuery = (new Tmux)->proc_query(1, 'testing');

        $this->assertIsString($monitorQuery);
        $this->assertStringNotContainsString('processrenames', $monitorQuery);

        foreach (array_keys(self::CONSUMED) as $column) {
            $this->assertStringNotContainsString(
                $column,
                $monitorQuery,
                "the stats query restates the sweep's {$column} term"
            );
        }
    }

    /**
     * The count and the batch are generated from one predicate, so they must
     * agree over every combination of unconsumed sources, not just one at a time.
     */
    #[Test]
    public function the_monitor_count_and_the_candidate_batch_agree_across_the_whole_source_matrix(): void
    {
        config(['nntmux_srrdb.enabled' => true]);

        $columns = array_keys(self::CONSUMED);
        $combinations = 1 << count($columns);

        for ($mask = 0; $mask < $combinations; $mask++) {
            // Every release is ready for every source it leaves unconsumed, so
            // the only thing distinguishing them is which sources are pending.
            $overrides = ['nfostatus' => 1];
            foreach ($columns as $bit => $column) {
                if (($mask & (1 << $bit)) !== 0) {
                    $overrides[$column] = 0;
                }
            }

            $id = $mask + 1;
            $this->insertRelease($id, $overrides);
            $this->insertReleaseFile($id, "release-{$id}.SDPORN.rar", 'A1B2C3D4');
            $this->insertMediaInfo($id);
        }

        // Every combination but the empty one leaves a source unconsumed.
        $expected = $combinations - 1;
        $queries = new NameFixingQueryService;

        $this->assertSame($expected, $queries->standardCandidateCount());
        $this->assertCount($expected, $queries->standardCandidateBatch('a', $combinations));
    }

    #[Test]
    public function the_fix_names_pane_gate_is_the_sweeps_own_count(): void
    {
        $this->insertRelease(1, ['proc_uid' => 0]);
        $this->insertRelease(2, ['categories_id' => Category::MOVIE_HD, 'proc_crc32' => 0]);
        $this->insertRelease(3, ['nfostatus' => 1]);

        $this->assertSame(2, $this->collectedProcessRenames());

        DB::table('releases')->whereIn('id', [1, 2])->update(['isrenamed' => 1]);

        $this->assertSame(0, $this->collectedProcessRenames());
    }

    /**
     * The `processrenames` value tmux's Fix Names pane gates on, as the monitor
     * collects it.
     */
    private function collectedProcessRenames(): int
    {
        // The rest of the aggregate stats query needs the full releases schema and
        // MySQL's IF(); only the derived rename gate is under test here.
        Log::spy();
        $monitor = new TmuxMonitorService;

        $runVar = new ReflectionProperty(TmuxMonitorService::class, 'runVar');
        $runVar->setValue($monitor, ['counts' => ['now' => []], 'settings' => [], 'timers' => ['query' => []]]);

        (new ReflectionMethod(TmuxMonitorService::class, 'getProcessCounts'))->invoke($monitor);

        return (int) $runVar->getValue($monitor)['counts']['now']['processrenames'];
    }

    /** @return list<int> */
    private function candidateIds(): array
    {
        return array_map(
            static fn (object $release): int => (int) $release->id,
            (new NameFixingQueryService)->standardCandidateBatch('a', 100)
        );
    }

    /**
     * Insert a release with every source consumed, then apply the overrides that
     * make the case under test.
     *
     * @param  array<string, int>  $overrides
     */
    private function insertRelease(int $id, array $overrides = []): void
    {
        DB::table('releases')->insert(array_merge([
            'id' => $id,
            'name' => sprintf('[10/88] "Artist-Title-FLAC-1971-GRP.part%02d.rar"', $id),
            'searchname' => 'Artist-Title-FLAC-1971-GRP',
            'fromname' => 'poster@example.com',
            'guid' => 'a-guid-'.$id,
            'leftguid' => 'a',
            'groups_id' => 1,
            'categories_id' => Category::OTHER_MISC,
            'size' => 1_000_000_000,
            'completion' => 100,
            'adddate' => now()->toDateTimeString(),
            'nzbstatus' => 1,
            'predb_id' => 0,
            'nfostatus' => 0,
            'isrenamed' => 0,
            'passwordstatus' => 0,
            'is_trusted_name' => 0,
        ], self::CONSUMED, $overrides));
    }

    private function insertReleaseFile(int $releaseId, string $name, string $crc32): void
    {
        DB::table('release_files')->insert([
            'releases_id' => $releaseId,
            'name' => $name,
            'crc32' => $crc32,
            'size' => 50_000_000,
        ]);
    }

    private function insertMediaInfo(int $releaseId): void
    {
        DB::table('media_infos')->insert([
            'releases_id' => $releaseId,
            'movie_name' => "Movie.Name.{$releaseId}.2026-GROUP",
        ]);
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
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

        DB::statement('DROP TABLE IF EXISTS release_files');
        DB::statement('CREATE TABLE release_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            releases_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            crc32 VARCHAR(8) NULL,
            size INTEGER NOT NULL DEFAULT 0
        )');

        DB::statement('DROP TABLE IF EXISTS media_infos');
        DB::statement('CREATE TABLE media_infos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            releases_id INTEGER NOT NULL,
            movie_name VARCHAR(255)
        )');
    }
}
