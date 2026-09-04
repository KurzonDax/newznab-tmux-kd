<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionCleanupService;
use App\Services\NNTP\NNTPService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * `maxnzbsprocessed` is an unvalidated admin text field, so it can be stored as 0. The
 * creation loop continues while an iteration filled its batch, which `0 >= 0` satisfies
 * even when the iteration processed nothing -- so the loop spun forever against a drained
 * queue. The limit is now positive by construction, and a drained iteration ends the loop.
 */
class ReleaseCreationLoopLimitTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * Guards the test against hanging if the loop ever fails to terminate again.
     */
    private const RUNAWAY_ITERATIONS = 5;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'maxnzbsprocessed' => '0'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        DB::statement(
            'CREATE TABLE releases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                guid VARCHAR NOT NULL,
                name VARCHAR NOT NULL,
                searchname VARCHAR NOT NULL,
                fromname VARCHAR NULL,
                categories_id INTEGER NOT NULL DEFAULT 0,
                groups_id INTEGER NOT NULL DEFAULT 0,
                postdate DATETIME NULL,
                nzbstatus INTEGER NOT NULL DEFAULT 0,
                iscategorized INTEGER NOT NULL DEFAULT 0
            )'
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    public function test_a_stored_zero_limit_does_not_stop_the_loop_from_terminating(): void
    {
        $creation = new DrainedReleaseCreationService(self::RUNAWAY_ITERATIONS);
        $service = new ReleaseProcessingService(
            releaseCreationService: $creation,
            collectionCleanupService: $this->createMock(CollectionCleanupService::class),
        );
        $service->setEchoCLI(false);

        $totals = $this->runCreationLoop($service);

        $this->assertSame(1000, $service->getReleaseCreationLimit());
        $this->assertSame(1, $creation->calls);
        $this->assertSame(1000, $creation->limitSeen);
        $this->assertSame(['releases' => 0, 'nzbs' => 0, 'dupes' => 0, 'iterations' => 1], $totals);
    }

    /**
     * @return array{releases: int, nzbs: int, dupes: int, iterations: int}
     */
    private function runCreationLoop(ReleaseProcessingService $service): array
    {
        $loop = new ReflectionMethod(ReleaseProcessingService::class, 'runReleaseCreationLoop');

        /** @var array{releases: int, nzbs: int, dupes: int, iterations: int} $totals */
        $totals = $loop->invoke($service, null, 0, 0, $this->createMock(NNTPService::class));

        return $totals;
    }
}

/**
 * Reports a drained queue on every call: the state the runaway loop could not exit from.
 */
class DrainedReleaseCreationService extends ReleaseCreationService
{
    public int $calls = 0;

    public int $limitSeen = 0;

    public function __construct(private readonly int $runawayIterations) {}

    /**
     * @return array{added: int, dupes: int}
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI): array
    {
        $this->calls++;
        $this->limitSeen = $limit;

        if ($this->calls > $this->runawayIterations) {
            throw new RuntimeException('The release creation loop did not terminate on a drained queue.');
        }

        return ['added' => 0, 'dupes' => 0];
    }
}
