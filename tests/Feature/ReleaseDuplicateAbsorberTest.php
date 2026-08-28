<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DuplicateAbsorbOutcome;
use App\Facades\Search;
use App\Models\Collection;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Support\Data\NzbReplaceResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseDuplicateAbsorberTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbDirectory;

    protected function bootstrapSettings(): array
    {
        return ['nzbsplitlevel' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbDirectory = $this->makeTempDirectory('duplicate-absorb').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized');
            $table->string('display_name')->nullable();
            $table->string('guid', 36)->unique();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('totalpart');
            $table->unsignedInteger('declaredfiles')->nullable();
            $table->double('completion')->default(0);
            $table->integer('nzbstatus')->default(NzbService::NZB_ADDED);
            $table->integer('nfostatus')->default(0);
            $table->integer('passwordstatus')->default(1);
            $table->integer('haspreview')->default(0);
            $table->unsignedInteger('pp_timeout_count')->default(2);
            $table->unsignedInteger('proc_nfo')->default(1);
            $table->unsignedInteger('proc_files')->default(1);
            $table->unsignedInteger('proc_srr')->default(1);
            $table->unsignedInteger('proc_crc32')->default(1);
            $table->unsignedInteger('proc_uid')->default(1);
            $table->unsignedInteger('proc_hash16k')->default(1);
            $table->unsignedInteger('proc_par2')->default(1);
            $table->unsignedInteger('proc_srrdb')->default(1);
            $table->unsignedInteger('proc_xxx')->default(1);
            $table->unsignedInteger('proc_media_movie')->default(1);
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedInteger('declaredfiles')->default(0);
            $table->unsignedTinyInteger('absorb_attempts')->default(0);
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_more_complete_incoming_nzb_upgrades_the_anchor_in_place_and_requeues_evidence(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1)->andReturnTrue();

        $anchor = $this->anchor();
        $nzb = app(NzbService::class);
        $this->writeStoredNzb($nzb, (string) $anchor->guid, $this->nzbXml('old@example.test', 1, 2));

        $result = app(ReleaseDuplicateAbsorber::class)->absorbXml(
            $anchor,
            $this->nzbXml('new@example.test', 2, 2),
            incomingSize: 2_000,
            incomingDeclaredFiles: 1,
            incomingCompletion: 100.0,
        );

        $this->assertSame(DuplicateAbsorbOutcome::Absorbed, $result->outcome);
        $this->assertSame(1, DB::table('releases')->count());

        $stored = DB::table('releases')->first();
        $this->assertNotNull($stored);
        $this->assertSame(1, (int) $stored->id);
        $this->assertSame(str_repeat('a', 36), $stored->guid);
        $this->assertSame(2_000, (int) $stored->size);
        $this->assertSame(1, (int) $stored->totalpart);
        $this->assertSame(1, (int) $stored->declaredfiles);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(-1, (int) $stored->nfostatus);
        $this->assertSame(-1, (int) $stored->passwordstatus);
        $this->assertSame(-1, (int) $stored->haspreview);
        $this->assertSame(0, (int) $stored->pp_timeout_count);
        $this->assertSame(0, (int) $stored->proc_files);

        $contents = $nzb->readNzbContents((string) $anchor->guid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new@example.test', $contents);
        $this->assertStringNotContainsString('old@example.test', $contents);
    }

    public function test_equal_or_lower_completion_leaves_the_anchor_and_nzb_unchanged(): void
    {
        Search::shouldReceive('updateRelease')->never();

        foreach ([50.0, 40.0] as $incomingCompletion) {
            DB::table('releases')->delete();
            $anchor = $this->anchor();
            $nzb = app(NzbService::class);
            $oldXml = $this->nzbXml('old@example.test', 1, 2);
            $this->writeStoredNzb($nzb, (string) $anchor->guid, $oldXml);

            $result = app(ReleaseDuplicateAbsorber::class)->absorbXml(
                $anchor,
                $this->nzbXml('new@example.test', 2, 2),
                incomingSize: 2_000,
                incomingDeclaredFiles: 1,
                incomingCompletion: $incomingCompletion,
            );

            $this->assertSame(DuplicateAbsorbOutcome::NotBetter, $result->outcome);
            $this->assertSame(1_000, (int) DB::table('releases')->value('size'));
            $this->assertSame(50.0, (float) DB::table('releases')->value('completion'));
            $this->assertSame($oldXml, $nzb->readNzbContents((string) $anchor->guid));
        }
    }

    public function test_an_anchor_whose_nzb_is_not_written_yet_defers_without_attempting(): void
    {
        Search::shouldReceive('updateRelease')->never();
        Log::spy();

        $anchor = $this->anchor(nzbstatus: NzbService::NZB_NONE);
        $nzb = new RecordingReplaceNzbService(app(CollectionCleanupService::class));

        $result = (new ReleaseDuplicateAbsorber($nzb))->absorbXml(
            $anchor,
            $this->nzbXml('new@example.test', 2, 2),
            incomingSize: 2_000,
            incomingDeclaredFiles: 1,
            incomingCompletion: 100.0,
        );

        $this->assertSame(DuplicateAbsorbOutcome::Deferred, $result->outcome);
        $this->assertSame([], $nzb->replaceCalls, 'A deferred absorb must not invoke replaceNzbContents().');
        $this->assertSame(1_000, (int) DB::table('releases')->value('size'));
        $this->assertSame(50.0, (float) DB::table('releases')->value('completion'));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_deferred_fragment_absorbs_once_the_anchor_nzb_lands(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1)->andReturnTrue();

        $anchor = $this->anchor(nzbstatus: NzbService::NZB_NONE);
        $absorber = app(ReleaseDuplicateAbsorber::class);
        $incomingXml = $this->nzbXml('new@example.test', 2, 2);

        $deferred = $absorber->absorbXml($anchor, $incomingXml, 2_000, 1, 100.0);
        $this->assertSame(DuplicateAbsorbOutcome::Deferred, $deferred->outcome);

        // The NZB-creation stage catches up: the file lands and nzbstatus flips.
        $nzb = app(NzbService::class);
        $this->writeStoredNzb($nzb, (string) $anchor->guid, $this->nzbXml('old@example.test', 1, 2));
        DB::table('releases')->where('id', 1)->update(['nzbstatus' => NzbService::NZB_ADDED]);

        $retried = $absorber->absorbXml(Release::query()->findOrFail(1), $incomingXml, 2_000, 1, 100.0);

        $this->assertSame(DuplicateAbsorbOutcome::Absorbed, $retried->outcome);
        $this->assertStringContainsString('new@example.test', (string) $nzb->readNzbContents((string) $anchor->guid));
    }

    public function test_an_attempted_collection_absorb_failure_increments_the_durable_counter(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $anchor = $this->anchor();
        $collection = $this->collection(200);
        $nzb = new StubCollectionNzbService(
            $this->nzbXml('new@example.test', 2, 2),
            NzbReplaceResult::renameFailure('forced rename failure'),
        );
        $absorber = new ReleaseDuplicateAbsorber($nzb);

        $first = $absorber->absorbCollection($anchor, $collection, 100.0);
        $this->assertSame(DuplicateAbsorbOutcome::Failed, $first->outcome);
        $this->assertSame(1, $first->attempts);
        $this->assertStringContainsString('forced rename failure', $first->reason);
        $this->assertSame(1, (int) DB::table('collections')->where('id', 200)->value('absorb_attempts'));

        $second = $absorber->absorbCollection($anchor, Collection::query()->findOrFail(200), 100.0);
        $this->assertSame(2, $second->attempts);
        $this->assertSame(2, (int) DB::table('collections')->where('id', 200)->value('absorb_attempts'));
    }

    public function test_a_deferred_collection_absorb_counts_nothing_and_renders_nothing(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $anchor = $this->anchor(nzbstatus: NzbService::NZB_NONE);
        $collection = $this->collection(200);
        $nzb = new StubCollectionNzbService(
            $this->nzbXml('new@example.test', 2, 2),
            NzbReplaceResult::renameFailure('forced rename failure'),
        );

        $result = (new ReleaseDuplicateAbsorber($nzb))->absorbCollection($anchor, $collection, 100.0);

        $this->assertSame(DuplicateAbsorbOutcome::Deferred, $result->outcome);
        $this->assertSame([], $nzb->buildCalls, 'A deferred absorb must not render the incoming NZB.');
        $this->assertSame(0, (int) DB::table('collections')->where('id', 200)->value('absorb_attempts'));
    }

    public function test_an_absorb_that_fails_by_exception_is_still_a_counted_attempt(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $anchor = $this->anchor();
        $collection = $this->collection(200);
        $nzb = new ThrowingReplaceNzbService(app(CollectionCleanupService::class));

        $result = (new ReleaseDuplicateAbsorber($nzb))->absorbCollection($anchor, $collection, 100.0);

        $this->assertSame(DuplicateAbsorbOutcome::Failed, $result->outcome);
        $this->assertSame(1, $result->attempts);
        $this->assertStringContainsString('forced replace explosion', $result->reason);
        $this->assertSame(1, (int) DB::table('collections')->where('id', 200)->value('absorb_attempts'));
    }

    public function test_a_failed_attempt_below_the_cap_can_still_succeed_later(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1)->andReturnTrue();

        $anchor = $this->anchor();
        $collection = $this->collection(200);
        $incomingXml = $this->nzbXml('new@example.test', 2, 2);

        $failing = new ReleaseDuplicateAbsorber(new StubCollectionNzbService(
            $incomingXml,
            NzbReplaceResult::tempFileOpenFailure('forced open failure'),
        ));
        $failed = $failing->absorbCollection($anchor, $collection, 100.0);
        $this->assertSame(DuplicateAbsorbOutcome::Failed, $failed->outcome);
        $this->assertSame(1, $failed->attempts);
        $this->assertLessThan(ReleaseDuplicateAbsorber::MAX_ABSORB_ATTEMPTS, $failed->attempts);

        $succeeding = new ReleaseDuplicateAbsorber(new StubCollectionNzbService(
            $incomingXml,
            NzbReplaceResult::success(),
        ));
        $retried = $succeeding->absorbCollection(
            Release::query()->findOrFail(1),
            Collection::query()->findOrFail(200),
            100.0,
        );

        $this->assertSame(DuplicateAbsorbOutcome::Absorbed, $retried->outcome);
        $this->assertSame(2_000, (int) DB::table('releases')->value('size'));
    }

    private function anchor(int $nzbstatus = NzbService::NZB_ADDED): Release
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'ReleaseName',
            'searchname' => 'ReleaseName',
            'searchname_normalized' => 'ReleaseName',
            'guid' => str_repeat('a', 36),
            'size' => 1_000,
            'totalpart' => 1,
            'declaredfiles' => 1,
            'completion' => 50.0,
            'nzbstatus' => $nzbstatus,
        ]);

        return Release::query()->findOrFail(1);
    }

    private function collection(int $id): Collection
    {
        DB::table('collections')->insert([
            'id' => $id,
            'filesize' => 2_000,
            'declaredfiles' => 1,
            'absorb_attempts' => 0,
        ]);

        return Collection::query()->findOrFail($id);
    }

    private function writeStoredNzb(NzbService $nzb, string $guid, string $contents): void
    {
        file_put_contents($nzb->getNzbPath($guid, 0, true), gzencode($contents));
    }

    private function nzbXml(string $messageId, int $segments, int $declaredSegments): string
    {
        $segmentXml = '';
        for ($number = 1; $number <= $segments; $number++) {
            $segmentXml .= '<segment bytes="1000" number="'.$number.'">'.$messageId.'</segment>';
        }

        return '<nzb><file poster="poster@example.test" date="1700000000" subject="ReleaseName yEnc (1/'
            .$declaredSegments.')"><groups><group>alt.test</group></groups><segments>'.$segmentXml
            .'</segments></file></nzb>';
    }
}

/**
 * Fails the test if the deferral gate ever lets a replace through.
 */
final class RecordingReplaceNzbService extends NzbService
{
    /**
     * @var list<string>
     */
    public array $replaceCalls = [];

    public function replaceNzbContents(string $releaseGuid, string $nzbXml): NzbReplaceResult
    {
        $this->replaceCalls[] = $releaseGuid;

        return parent::replaceNzbContents($releaseGuid, $nzbXml);
    }
}

/**
 * Explodes inside the absorb transaction, proving an exception is converted
 * to a counted attempt instead of escaping the absorber.
 */
final class ThrowingReplaceNzbService extends NzbService
{
    public function buildNzbContentsForCollection(Release $release, int $collectionId): string|false
    {
        return '<nzb><file poster="p" date="1" subject="s (1/1)"><groups><group>alt.test</group></groups>'
            .'<segments><segment bytes="1" number="1">x@example.test</segment></segments></file></nzb>';
    }

    public function replaceNzbContents(string $releaseGuid, string $nzbXml): NzbReplaceResult
    {
        throw new \RuntimeException('forced replace explosion');
    }
}

/**
 * Canned collection rendering and a forced replace outcome, so the
 * collection-absorb path can be driven without CBP tables or stored files.
 */
final class StubCollectionNzbService extends NzbService
{
    /**
     * @var list<int>
     */
    public array $buildCalls = [];

    public function __construct(
        private readonly string $renderedXml,
        private readonly NzbReplaceResult $replaceResult,
    ) {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function buildNzbContentsForCollection(Release $release, int $collectionId): string|false
    {
        $this->buildCalls[] = $collectionId;

        return $this->renderedXml;
    }

    public function replaceNzbContents(string $releaseGuid, string $nzbXml): NzbReplaceResult
    {
        return $this->replaceResult;
    }
}
