<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbParseFailure;
use App\Services\MetadataProcessing\NfoProcessingCandidateQuery;
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
        DB::table('settings')->where('name', 'lookupnfo')->update(['value' => '0']);
        Cache::flush();

        $this->insertRelease(3, nzbStatus: 1, nfoStatus: -4);
        $this->insertRelease(4, nzbStatus: 1, nfoStatus: -5);
        $this->insertRelease(5, nzbStatus: 1, nfoStatus: -7);
        $this->insertRelease(6, nzbStatus: 1, nfoStatus: NfoService::NFO_FAILED_ARCHIVE);
        $this->insertRelease(7, nzbStatus: 0, nfoStatus: -5);
        DB::table('release_files')->insert([
            ['releases_id' => 3, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 4, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 5, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 6, 'name' => 'release.nfo', 'size' => 1_000],
            ['releases_id' => 7, 'name' => 'release.nfo', 'size' => 1_000],
        ]);

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertEqualsCanonicalizing([4, 5], $service->archiveReleaseIds);
        $this->assertSame(-4, DB::table('releases')->where('id', 3)->value('nfostatus'));
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 4)->value('nfostatus'));
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 5)->value('nfostatus'));
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 6)->value('nfostatus'));
        $this->assertSame(-5, DB::table('releases')->where('id', 7)->value('nfostatus'));
    }

    #[Test]
    public function a_release_with_a_main_retry_left_survives_the_archive_pass_in_the_same_run(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('settings')->where('name', 'maxnforetries')->update(['value' => '3']);
        Cache::flush();

        // -3 sits inside the main window; one failed attempt drops it to the retry floor (-4),
        // which the archive pass must leave alone until a later run spends that last retry.
        $this->insertRelease(1, nzbStatus: 1, nfoStatus: -3);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'release.nfo', 'size' => 1_000]);

        $this->app->instance(NzbContentsService::class, $this->failingContentsService());

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame([], $service->archiveReleaseIds);
        $this->assertSame(-4, DB::table('releases')->where('id', 1)->value('nfostatus'));
    }

    #[Test]
    public function a_release_the_main_pass_exhausts_reaches_the_archive_pass_in_the_same_run(): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('settings')->where('name', 'maxnforetries')->update(['value' => '3']);
        Cache::flush();

        // -4 is the last main retry. Spending it in this run drops the release past the floor,
        // so the archive pass — which queries after the main loop — picks it up immediately.
        $this->insertRelease(1, nzbStatus: 1, nfoStatus: -4);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'release.nfo', 'size' => 1_000]);

        $this->app->instance(NzbContentsService::class, $this->failingContentsService());

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        $this->assertSame([1], $service->archiveReleaseIds);
        $this->assertSame([-5], $service->archiveReleaseStatuses);
        $this->assertSame(NfoService::NFO_FAILED_ARCHIVE, DB::table('releases')->where('id', 1)->value('nfostatus'));
    }

    #[Test]
    public function the_main_pass_keeps_the_retry_floor_the_archive_pass_gives_up(): void
    {
        $this->assertSame([-4, -3, -2, -1], $this->mainPassStatuses(3));
        $this->assertSame([-9, -8, -7, -6, -5], $this->archivePassStatuses(3));
    }

    #[Test]
    public function the_clamped_retry_floor_leaves_only_the_failed_status_to_the_archive_pass(): void
    {
        foreach ([7, 8, 12] as $retries) {
            $this->assertSame(
                [-8, -7, -6, -5, -4, -3, -2, -1],
                $this->mainPassStatuses($retries),
                'Main pass window for maxnforetries='.$retries,
            );
            $this->assertSame(
                [NfoService::NFO_FAILED],
                $this->archivePassStatuses($retries),
                'Archive pass window for maxnforetries='.$retries,
            );
        }
    }

    #[Test]
    public function raising_the_retry_setting_cannot_desynchronise_the_two_windows(): void
    {
        // Run once at 3 retries so any cache the archive pass keeps is warm and holds floor -4.
        $this->seedStatusLadder(3, mainPassEnabled: false);
        (new RecordingArchiveNfoService)->processNfoFiles(Mockery::mock(NNTPService::class));

        // Raise the budget the way an admin save does, without clearing any cache.
        DB::table('settings')->where('name', 'maxnforetries')->update(['value' => '5']);
        $this->seedStatusLadderRows();

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        // The main pass reads the setting uncached, so its floor is already -6. An archive pass
        // still holding the old floor would reclaim -6 and -5 and fail them terminally.
        $this->assertEqualsCanonicalizing([-9, -8, -7], $service->archiveReleaseStatuses);
    }

    #[Test]
    public function the_retry_windows_partition_every_status_for_every_setting(): void
    {
        foreach ([-5, -1, ...range(0, 10)] as $retries) {
            $mainStatuses = $this->mainPassStatuses($retries);
            $archiveStatuses = $this->archivePassStatuses($retries);

            $this->assertSame(
                [],
                array_values(array_intersect($mainStatuses, $archiveStatuses)),
                'Statuses claimed by both passes for maxnforetries='.$retries,
            );

            $union = array_merge($mainStatuses, $archiveStatuses);
            sort($union);

            $this->assertSame(
                range(NfoService::NFO_FAILED, NfoService::NFO_UNPROC),
                $union,
                'Statuses covered by maxnforetries='.$retries,
            );
        }
    }

    /**
     * Statuses the main retry pass admits, lowest first.
     *
     * @return list<int>
     */
    private function mainPassStatuses(int $retries): array
    {
        $this->seedStatusLadder($retries, mainPassEnabled: true);

        return NfoProcessingCandidateQuery::query()
            ->pluck('nfostatus')
            ->map(fn (mixed $status): int => (int) $status)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Statuses the archive pass admits, lowest first. The main pass is switched off by a
     * size filter no seeded release passes, so only the archive pass selects anything.
     *
     * @return list<int>
     */
    private function archivePassStatuses(int $retries): array
    {
        $this->seedStatusLadder($retries, mainPassEnabled: false);

        $service = new RecordingArchiveNfoService;
        $service->processNfoFiles(Mockery::mock(NNTPService::class));

        return collect($service->archiveReleaseStatuses)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Seed one archive-eligible release per retry status, with release N sitting at status -N.
     *
     * The main pass is switched off through `lookupnfo`, the setting that actually gates it, so
     * the archive pass is observed alone without distorting any of its own selection inputs.
     */
    private function seedStatusLadder(int $retries, bool $mainPassEnabled): void
    {
        DB::table('settings')->where('name', 'maxnforetries')->update(['value' => (string) $retries]);
        DB::table('settings')->where('name', 'lookupnfo')->update(['value' => $mainPassEnabled ? '1' : '0']);
        Cache::flush();

        $this->seedStatusLadderRows();
    }

    /**
     * One archive-eligible release per retry status, with release N sitting at status -N.
     */
    private function seedStatusLadderRows(): void
    {
        DB::table('release_files')->delete();
        DB::table('releases')->delete();

        foreach (range(1, 10) as $id) {
            $this->insertRelease($id, nzbStatus: 1, nfoStatus: -$id);
            DB::table('release_files')->insert([
                'releases_id' => $id,
                'name' => 'release.nfo',
                'size' => 1_000,
            ]);
        }
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

    /**
     * A main pass whose NZB lookup always misses, spending one retry per release.
     */
    private function failingContentsService(): NzbContentsService
    {
        $contents = Mockery::mock(NzbContentsService::class);
        $contents->shouldReceive('setNntp')->once();
        $contents->shouldReceive('setNfo')->once();
        $contents->shouldReceive('getNfoFromNzb')->once()->andReturn(NzbParseFailure::Missing);

        return $contents;
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

    /** @var list<int> The nfostatus each release carried when it was handed to this pass. */
    public array $archiveReleaseStatuses = [];

    public function attemptNfoFromArchive(string $guid, int $releaseId, NNTPService $nntp): string|false
    {
        $this->archiveReleaseIds[] = $releaseId;
        $this->archiveReleaseStatuses[] = (int) DB::table('releases')->where('id', $releaseId)->value('nfostatus');

        return false;
    }
}
