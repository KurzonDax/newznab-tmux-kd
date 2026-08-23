<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\NameFixing\NameFixingQueryService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ReleaseLifecycleEligibilityGapTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createReleasesTable();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function the_standard_name_sweep_excludes_an_unrenamed_release_outside_other(): void
    {
        $this->insertRelease(1, Category::OTHER_MISC, nfostatus: 0);
        $this->insertRelease(2, Category::MUSIC_LOSSLESS, nfostatus: 0);

        $this->assertSame([1], $this->standardNameCandidateIds());
    }

    #[Test]
    public function the_standard_name_sweep_excludes_every_source_while_nfo_is_pending(): void
    {
        $this->insertRelease(1, Category::OTHER_MISC, nfostatus: -1);

        $this->assertSame([], $this->standardNameCandidateIds());

        DB::table('releases')->where('id', 1)->update(['nfostatus' => 0]);

        $this->assertSame([1], $this->standardNameCandidateIds());
    }

    #[Test]
    public function the_standard_name_sweep_excludes_every_source_after_nfo_retries_are_exhausted(): void
    {
        $this->insertRelease(1, Category::OTHER_MISC, nfostatus: -9);

        $this->assertSame([], $this->standardNameCandidateIds());
    }

    #[Test]
    public function par2_name_fixing_can_mark_a_release_done_before_its_nzb_exists(): void
    {
        $this->insertRelease(1, Category::OTHER_MISC, nfostatus: -1, nzbstatus: 0);

        $this->assertSame([1], $this->sourceCandidateIds(NameFixingQueryService::SOURCE_PAR2));

        DB::table('releases')->where('id', 1)->update([
            'nzbstatus' => 1,
            'proc_par2' => 1,
        ]);

        $this->assertSame([], $this->sourceCandidateIds(NameFixingQueryService::SOURCE_PAR2));
    }

    /** @return list<int> */
    private function standardNameCandidateIds(): array
    {
        return array_map(
            static fn (object $release): int => (int) $release->id,
            (new NameFixingQueryService)->standardCandidateBatch('a', 100)
        );
    }

    /** @return list<int> */
    private function sourceCandidateIds(string $source): array
    {
        return array_map(
            static fn (object $release): int => (int) $release->id,
            (new NameFixingQueryService)->candidateBatch($source, 1, 1, 0, 100)
        );
    }

    private function insertRelease(int $id, int $categoryId, int $nfostatus, int $nzbstatus = 1): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => sprintf('[10/88] "Artist-Title-FLAC-1971-GRP.part%02d.rar"', $id),
            'searchname' => 'Artist-Title-FLAC-1971-GRP',
            'fromname' => 'poster@example.com',
            'guid' => 'a-guid-'.$id,
            'leftguid' => 'a',
            'groups_id' => 1,
            'categories_id' => $categoryId,
            'size' => 1_000_000_000,
            'adddate' => now()->toDateTimeString(),
            'nzbstatus' => $nzbstatus,
            'predb_id' => 0,
            'nfostatus' => $nfostatus,
            'proc_nfo' => 0,
            'proc_uid' => 0,
            'proc_files' => 0,
            'proc_par2' => 0,
            'proc_hash16k' => 0,
            'proc_srr' => 0,
            'proc_crc32' => 0,
            'isrenamed' => 0,
            'passwordstatus' => 0,
        ]);
    }

    private function createReleasesTable(): void
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
            adddate DATETIME NOT NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            predb_id INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            passwordstatus INTEGER NOT NULL DEFAULT 0
        )');
    }
}
