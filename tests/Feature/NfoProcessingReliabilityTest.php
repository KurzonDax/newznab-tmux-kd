<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbParseFailure;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\PostProcessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class NfoProcessingReliabilityTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->nzbRoot = $this->makeTempDirectory('nntmux-nfo-reliability');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);
        $this->createSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'timeoutseconds' => '60',
            'maxnfoprocessed' => '100',
            'maxnforetries' => '8',
            'maxsizetoprocessnfo' => '0',
            'minsizetoprocessnfo' => '0',
            'nzbsplitlevel' => '1',
            'lookuppar2' => '0',
            'lookupnfo' => '1',
        ];
    }

    #[Test]
    public function only_nzb_ready_releases_are_offered_to_nfo_extraction(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        $this->insertRelease(1, nzbStatus: 1);
        $this->insertRelease(2, nzbStatus: 0);

        $releaseIds = [];
        $contents = Mockery::mock(NzbContentsService::class);
        $contents->shouldReceive('setNntp')->once();
        $contents->shouldReceive('setNfo')->once();
        $contents->shouldReceive('getNfoFromNzb')
            ->once()
            ->andReturnUsing(function (string $guid, int $releaseId, int $groupId, string $groupName) use (&$releaseIds): NzbParseFailure {
                $releaseIds[] = $releaseId;

                return NzbParseFailure::Missing;
            });
        $this->app->instance(NzbContentsService::class, $contents);

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame([1], $releaseIds);
        $this->assertStringContainsString('AND r.nzbstatus = 1', NfoService::NfoQueryString());
    }

    #[Test]
    public function the_archive_fallback_uses_the_configured_retry_band(): void
    {
        DB::table('settings')->where('name', 'maxnforetries')->update(['value' => '3']);
        DB::table('settings')->where('name', 'maxsizetoprocessnfo')->update(['value' => '500']);
        Cache::flush();

        $this->insertRelease(3, nzbStatus: 1, nfoStatus: -4);
        $this->insertRelease(4, nzbStatus: 1, nfoStatus: -7);
        $this->insertRelease(5, nzbStatus: 1, nfoStatus: NfoService::NFO_FAILED_ARCHIVE);
        $this->insertRelease(6, nzbStatus: 0, nfoStatus: -4);
        DB::table('release_files')->insert([
            ['releases_id' => 3, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 4, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 5, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 6, 'name' => 'release.nfo', 'size' => 1_000],
        ]);

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertEqualsCanonicalizing([3, 4], $service->archiveReleaseIds);
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 3)->value('nfostatus'));
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 4)->value('nfostatus'));
    }

    #[Test]
    public function a_missing_nzb_consumes_one_retry_without_becoming_terminal(): void
    {
        $this->insertRelease(5, nzbStatus: 1, nfoStatus: -3);

        $contents = $this->contentsService();
        $result = $contents->getNfoFromNzb($this->guid(5), 5, 1, 'alt.binaries.test');

        $this->assertSame(NzbParseFailure::Missing, $result);
        $this->assertSame(-3, DB::table('releases')->where('id', 5)->value('nfostatus'));

        $this->app->instance(NzbContentsService::class, $contents);
        (new RecordingArchiveNfoService)->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame(-4, DB::table('releases')->where('id', 5)->value('nfostatus'));
    }

    #[Test]
    public function a_broken_nzb_consumes_one_retry_without_becoming_terminal(): void
    {
        $this->insertRelease(6, nzbStatus: 1, nfoStatus: -4);
        $this->writeNzb(6, 'not xml');

        $contents = $this->contentsService();
        $result = $contents->getNfoFromNzb($this->guid(6), 6, 1, 'alt.binaries.test');

        $this->assertSame(NzbParseFailure::Broken, $result);
        $this->assertSame(-4, DB::table('releases')->where('id', 6)->value('nfostatus'));

        $this->app->instance(NzbContentsService::class, $contents);
        (new RecordingArchiveNfoService)->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame(-5, DB::table('releases')->where('id', 6)->value('nfostatus'));
    }

    #[Test]
    public function unavailable_nzb_storage_stops_the_pass_without_consuming_a_retry(): void
    {
        $this->insertRelease(8, nzbStatus: 1, nfoStatus: -3);
        $this->insertRelease(9, nzbStatus: 1, nfoStatus: -8);
        DB::table('release_files')->insert([
            'releases_id' => 9,
            'name' => 'release.nfo',
            'size' => 1_000,
        ]);

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('hasReadableNzbStorage')->once()->andReturnFalse();
        $this->app->instance(NzbService::class, $nzb);

        Log::shouldReceive('warning')
            ->once()
            ->with('NFO processing skipped because NZB storage is unavailable.');

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame(-3, DB::table('releases')->where('id', 8)->value('nfostatus'));
        $this->assertSame(-8, DB::table('releases')->where('id', 9)->value('nfostatus'));
        $this->assertSame([], $service->archiveReleaseIds);
    }

    #[Test]
    public function a_parsed_nzb_without_an_nfo_records_the_terminal_verdict(): void
    {
        $this->insertRelease(7, nzbStatus: 1, nfoStatus: -2);
        $this->writeNzb(7, <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
              <file poster="p@example.org" date="1700000000" subject="release.part01.rar yEnc (1/1)">
                <groups><group>alt.binaries.test</group></groups>
                <segments><segment bytes="900" number="1">archive@host</segment></segments>
              </file>
            </nzb>
            XML);

        $result = $this->contentsService()->getNfoFromNzb($this->guid(7), 7, 1, 'alt.binaries.test');

        $this->assertFalse($result);
        $this->assertSame(NfoService::NFO_NONFO, DB::table('releases')->where('id', 7)->value('nfostatus'));
    }

    private function contentsService(?NzbService $nzbService = null): NzbContentsService
    {
        return new NzbContentsService(
            $nzbService ?? app(NzbService::class),
            new NzbParserService,
            Mockery::mock(NNTPService::class),
            Mockery::mock(NfoService::class),
            app(PostProcessService::class),
        );
    }

    private function guid(int $id): string
    {
        return sprintf('%032x', $id);
    }

    private function writeNzb(int $id, string $contents): void
    {
        file_put_contents(
            app(NzbService::class)->getNzbPath($this->guid($id), 0, true),
            gzencode($contents),
        );
    }

    private function insertRelease(int $id, int $nzbStatus, int $nfoStatus = NfoService::NFO_UNPROC): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $this->guid($id),
            'groups_id' => 1,
            'name' => 'Release '.$id,
            'leftguid' => dechex($id)[0],
            'size' => 1_000,
            'nzbstatus' => $nzbStatus,
            'nfostatus' => $nfoStatus,
            'postdate' => '2026-01-01 00:00:00',
        ]);
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('DROP TABLE IF EXISTS release_files');
        DB::statement('DROP TABLE IF EXISTS release_nfos');
        DB::statement('DROP TABLE IF EXISTS usenet_groups');

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64) NOT NULL,
            groups_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            leftguid VARCHAR(1) NOT NULL,
            size INTEGER NOT NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT -1,
            completion DOUBLE NOT NULL DEFAULT 0,
            postdate DATETIME NULL
        )');
        DB::statement('CREATE TABLE release_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            releases_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            size INTEGER NOT NULL DEFAULT 0
        )');
        DB::statement('CREATE TABLE release_nfos (
            releases_id INTEGER PRIMARY KEY,
            nfo BLOB NULL
        )');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) NOT NULL
        )');
    }
}

class RecordingArchiveNfoService extends NfoService
{
    /** @var list<int> */
    public array $archiveReleaseIds = [];

    public function attemptNfoFromArchive(string $guid, int $releaseId, NNTPService $nntp): string|false
    {
        $this->archiveReleaseIds[] = $releaseId;

        return false;
    }
}
