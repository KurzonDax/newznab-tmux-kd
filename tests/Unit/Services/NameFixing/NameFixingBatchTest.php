<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NameFixing\Srrdb\SrrdbLookupService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class NameFixingBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux_srrdb.enabled' => false]);
    }

    #[Test]
    public function grouped_processing_fetches_sources_once_for_the_whole_batch(): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->exactly(5))
            ->method('select')
            ->willReturnCallback(function (string $sql): array {
                $this->assertStringNotContainsString('GROUP_CONCAT', $sql);
                $this->assertStringNotContainsString('GROUP BY r.id', $sql);

                if (str_contains($sql, 'r.proc_nfo')) {
                    // No cross-source gate stands in front of the OR list.
                    $this->assertStringNotContainsString('r.nfostatus > -1', $sql);
                    $this->assertStringNotContainsString('r.passwordstatus', $sql);
                    $this->assertStringNotContainsString('r.categories_id IN', $sql);

                    return [$this->processedRelease(10), $this->processedRelease(20)];
                }

                return [];
            });

        $serviceReflection = new ReflectionClass(NameFixingService::class);
        $service = $serviceReflection->newInstanceWithoutConstructor();
        $serviceReflection->getProperty('queries')
            ->setValue($service, new NameFixingQueryService($database));
        $serviceReflection->getProperty('updateService')
            ->setValue(
                $service,
                (new ReflectionClass(ReleaseUpdateService::class))->newInstanceWithoutConstructor()
            );

        $this->assertSame(
            ['checked' => 2, 'fixed' => 0],
            $service->processStandardBatch('a', 100, false)
        );
    }

    /**
     * The standard sweep is the tmux chain's catch-up pass: it has no adddate
     * window, so a release that aged past the odd methods' 6-hour limit while
     * one of them was stalled is still visited and leaves with its proc flag set.
     */
    #[Test]
    public function the_sweep_processes_an_aged_out_release_and_settles_its_par2_flag(): void
    {
        $release = $this->processedRelease(30);
        $release->proc_par2 = NameFixingService::PROC_PAR2_NONE;
        $release->adddate = '2026-08-20 00:00:00';

        $database = $this->createMock(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(function (string $sql) use ($release): array {
            if (str_contains($sql, 'r.proc_par2 = 0')) {
                $this->assertStringNotContainsString('adddate', $sql, 'the sweep has no time window');

                return [$release];
            }

            return [];
        });

        $updateService = $this->createPartialMock(ReleaseUpdateService::class, ['updateSingleColumn']);
        $updateService->expects($this->once())
            ->method('updateSingleColumn')
            ->with('proc_par2', NameFixingService::PROC_PAR2_DONE, 30);

        $serviceReflection = new ReflectionClass(NameFixingService::class);
        $service = $serviceReflection->newInstanceWithoutConstructor();
        $serviceReflection->getProperty('queries')->setValue($service, new NameFixingQueryService($database));
        $serviceReflection->getProperty('updateService')->setValue($service, $updateService);

        $par2Calls = [];
        $stats = $service->processStandardBatch('a', 100, false, function (object $candidate) use (&$par2Calls): bool {
            $par2Calls[] = (int) $candidate->releases_id;

            return false;
        });

        $this->assertSame([30], $par2Calls, 'the PAR2 callback is offered the aged-out release once');
        $this->assertSame(['checked' => 1, 'fixed' => 0], $stats);
    }

    /**
     * The sweep is the only unwindowed pass over SRRDB, so a release whose last
     * unconsumed source is `proc_srrdb` has to be looked up and settled here --
     * otherwise the expanded predicate would re-admit it on every cycle forever.
     */
    #[Test]
    public function the_sweep_looks_up_and_settles_srrdb_for_an_admitted_release(): void
    {
        config([
            'nntmux_srrdb.enabled' => true,
            'nntmux_srrdb.requests_per_second' => 0,
        ]);
        $this->createSrrdbLookupCache();
        Http::fake(['api.srrdb.com/v1/search/*' => Http::response(['resultsCount' => 0, 'results' => []], 200)]);

        $release = $this->processedRelease(40);
        $release->proc_srrdb = NameFixingService::PROC_SRRDB_NONE;

        $database = $this->createMock(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(function (string $sql) use ($release): array {
            if (str_contains($sql, 'r.proc_srrdb = 0')) {
                return [$release];
            }

            if (str_contains($sql, 'LENGTH(rf.crc32) = 8')) {
                return [(object) [
                    'releases_id' => 40,
                    'textstring' => 'Some.Release-GRP.rar',
                    'filename' => 'Some.Release-GRP.rar',
                    'crc32' => 'A1B2C3D4',
                    'size' => 50_000_000,
                ]];
            }

            return [];
        });

        $updateService = $this->createPartialMock(ReleaseUpdateService::class, ['updateSingleColumn']);
        $updateService->expects($this->once())
            ->method('updateSingleColumn')
            ->with('proc_srrdb', NameFixingService::PROC_SRRDB_DONE, 40);

        $service = $this->serviceWith($database, $updateService);

        $this->assertSame(['checked' => 1, 'fixed' => 0], $service->processStandardBatch('a', 100, false));
        Http::assertSent(
            static fn ($request): bool => str_contains($request->url(), 'archive-crc:A1B2C3D4')
        );
    }

    #[Test]
    public function the_sweep_skips_the_srrdb_leg_while_the_source_is_disabled(): void
    {
        config(['nntmux_srrdb.enabled' => false]);
        Http::fake();

        $release = $this->processedRelease(50);
        $release->proc_srrdb = NameFixingService::PROC_SRRDB_NONE;

        $database = $this->createMock(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(function (string $sql) use ($release): array {
            $this->assertStringNotContainsString('LENGTH(rf.crc32) = 8', $sql);

            return str_contains($sql, 'r.proc_nfo') ? [$release] : [];
        });

        $updateService = $this->createPartialMock(ReleaseUpdateService::class, ['updateSingleColumn']);
        $updateService->expects($this->never())->method('updateSingleColumn');

        $service = $this->serviceWith($database, $updateService);

        $this->assertSame(['checked' => 1, 'fixed' => 0], $service->processStandardBatch('a', 100, false));
        Http::assertNothingSent();
    }

    /**
     * A release admitted on some other source can still be one the SRRDB worker
     * refuses. Settling `proc_srrdb` for it would consume the source before the
     * archive CRCs additional processing has yet to write ever exist.
     */
    #[Test]
    public function the_sweep_leaves_srrdb_pending_when_the_release_has_no_archive_crc(): void
    {
        config(['nntmux_srrdb.enabled' => true]);
        Http::fake();

        $release = $this->processedRelease(60);
        $release->proc_srrdb = NameFixingService::PROC_SRRDB_NONE;
        $release->proc_uid = NameFixingService::PROC_UID_NONE;

        $database = $this->createMock(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'r.proc_srrdb = 0') ? [$release] : []
        );

        $updateService = $this->createPartialMock(ReleaseUpdateService::class, ['updateSingleColumn']);
        $updateService->expects($this->once())
            ->method('updateSingleColumn')
            ->with('proc_uid', NameFixingService::PROC_UID_DONE, 60);

        $service = $this->serviceWith($database, $updateService);

        $this->assertSame(['checked' => 1, 'fixed' => 0], $service->processStandardBatch('a', 100, false));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_sweep_leaves_srrdb_pending_for_a_release_whose_name_is_already_trusted(): void
    {
        config(['nntmux_srrdb.enabled' => true]);
        Http::fake();

        $release = $this->processedRelease(70);
        $release->proc_srrdb = NameFixingService::PROC_SRRDB_NONE;
        $release->proc_uid = NameFixingService::PROC_UID_NONE;
        $release->is_trusted_name = 1;

        $database = $this->createMock(ConnectionInterface::class);
        $database->method('select')->willReturnCallback(function (string $sql) use ($release): array {
            if (str_contains($sql, 'r.proc_srrdb = 0')) {
                return [$release];
            }

            if (str_contains($sql, 'LENGTH(rf.crc32) = 8')) {
                return [(object) [
                    'releases_id' => 70,
                    'textstring' => 'Some.Release-GRP.rar',
                    'filename' => 'Some.Release-GRP.rar',
                    'crc32' => 'A1B2C3D4',
                    'size' => 50_000_000,
                ]];
            }

            return [];
        });

        $updateService = $this->createPartialMock(ReleaseUpdateService::class, ['updateSingleColumn']);
        $updateService->expects($this->once())
            ->method('updateSingleColumn')
            ->with('proc_uid', NameFixingService::PROC_UID_DONE, 70);

        $service = $this->serviceWith($database, $updateService);

        $this->assertSame(['checked' => 1, 'fixed' => 0], $service->processStandardBatch('a', 100, false));
        Http::assertNothingSent();
    }

    private function serviceWith(
        ConnectionInterface $database,
        ReleaseUpdateService $updateService,
    ): NameFixingService {
        $reflection = new ReflectionClass(NameFixingService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('queries')->setValue($service, new NameFixingQueryService($database));
        $reflection->getProperty('updateService')->setValue($service, $updateService);
        $reflection->getProperty('srrdbLookupService')->setValue($service, new SrrdbLookupService);
        $reflection->getProperty('filePrioritizer')->setValue($service, new FilePrioritizer);

        return $service;
    }

    private function createSrrdbLookupCache(): void
    {
        Schema::create('srrdb_lookups', function (Blueprint $table): void {
            $table->string('crc32', 8)->primary();
            $table->string('status', 20);
            $table->json('payload')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    private function processedRelease(int $id): object
    {
        return (object) [
            'releases_id' => $id,
            'guid' => "guid-{$id}",
            'name' => "release-{$id}",
            'searchname' => "release-{$id}",
            'size' => 1000,
            'relsize' => 1000,
            'completion' => 100,
            'groupName' => 'alt.binaries.test',
            'categoryid' => 8010,
            'nfostatus' => 0,
            'proc_nfo' => NameFixingService::PROC_NFO_DONE,
            'proc_files' => NameFixingService::PROC_FILES_DONE,
            'proc_par2' => NameFixingService::PROC_PAR2_DONE,
            'proc_uid' => NameFixingService::PROC_UID_DONE,
            'proc_hash16k' => NameFixingService::PROC_HASH16K_DONE,
            'proc_srr' => NameFixingService::PROC_SRR_DONE,
            'proc_crc32' => NameFixingService::PROC_CRC_DONE,
            'proc_srrdb' => NameFixingService::PROC_SRRDB_DONE,
            'is_trusted_name' => 0,
        ];
    }
}
