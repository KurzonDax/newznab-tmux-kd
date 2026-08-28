<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImagerySkipArtifact;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * The Imagery disk skip ledger (ADR 0013): the release settles as processed so
 * the workers stop re-claiming it during a squeeze, and the ledger row is what
 * survives to be requeued once space is reclaimed.
 */
class ImageryDiskSkipLedgerTest extends TestCase
{
    use CreatesProcessingConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->unsignedInteger('categories_id')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('videostatus')->default(0);
            $table->integer('jpgstatus')->default(0);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('rarinnerfilecount')->default(0);
            $table->unsignedInteger('pp_timeout_count')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title')->default('');
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title')->default('');
            $table->unsignedInteger('root_categories_id')->nullable();
        });

        DB::table('root_categories')->insert([['id' => 6000, 'title' => 'XXX', 'generate_previews' => 1]]);
        DB::table('categories')->insert([['id' => 6010, 'root_categories_id' => 6000]]);

        Schema::create('release_imagery_disk_skips', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->string('suppressed', 32);
            $table->timestamps();
            $table->unique('releases_id');
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('passworded')->default(0);
            $table->string('crc32')->default('');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_disk_skip_settles_the_release_and_records_the_ledger_row(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'haspreview' => -1]);

        $context = $this->makeContext();
        $context->imagerySkippedByDiskGuard = [ImagerySkipArtifact::Sample, ImagerySkipArtifact::Preview];

        $this->makeManager()->finalizeRelease($context, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(0, (int) $release->haspreview, 'A disk skip settles as a plain 0: it has no sentinel of its own.');
        $this->assertSame(0, (int) $release->jpgstatus);
        $this->assertNull($release->additional_pp_claim_token, 'Settled, so the pending queries stop re-claiming it.');

        $row = DB::table('release_imagery_disk_skips')->where('releases_id', 1)->first();
        $this->assertNotNull($row);
        $this->assertSame('sample,preview', $row->suppressed);
        $this->assertNotNull($row->created_at, 'The requeue unit needs to know when the squeeze happened.');
    }

    public function test_a_second_squeeze_refreshes_the_single_row_for_a_release(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010]);

        $first = $this->makeContext();
        $first->imagerySkippedByDiskGuard = [ImagerySkipArtifact::Sample, ImagerySkipArtifact::Preview];
        $this->makeManager()->finalizeRelease($first, false);

        $second = $this->makeContext();
        $second->imagerySkippedByDiskGuard = [ImagerySkipArtifact::Preview];
        $this->makeManager()->finalizeRelease($second, false);

        $this->assertSame(1, DB::table('release_imagery_disk_skips')->where('releases_id', 1)->count());
        $this->assertSame('preview', DB::table('release_imagery_disk_skips')->where('releases_id', 1)->value('suppressed'));
    }

    public function test_an_ordinary_finalize_writes_no_ledger_row(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010]);

        $this->makeManager()->finalizeRelease($this->makeContext(), false);

        $this->assertSame(0, DB::table('release_imagery_disk_skips')->count());
    }

    public function test_the_requeue_command_owns_row_deletion_not_the_pipeline(): void
    {
        // Rows are cleared by the recovery command alone: adding a DELETE to
        // every release's finalize transaction to catch the rare release
        // re-pended by another tool would cost more than the wasted reprocess
        // it saves, and ADR 0013 already accepts rows that yield nothing.
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010]);
        DB::table('release_imagery_disk_skips')->insert([
            'releases_id' => 1,
            'suppressed' => 'sample,preview',
        ]);

        $this->makeManager()->finalizeRelease($this->makeContext(), false);

        $this->assertSame(1, DB::table('release_imagery_disk_skips')->where('releases_id', 1)->count());

        $this->artisan('releases:requeue-imagery-disk-skips', ['--apply' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('release_imagery_disk_skips')->count());
    }

    public function test_the_requeue_command_reports_without_changing_anything_by_default(): void
    {
        $this->seedSkippedRelease();

        $this->artisan('releases:requeue-imagery-disk-skips')
            ->expectsOutputToContain('Dry run: 1 ledgered release would be re-queued.')
            ->expectsOutputToContain('Dry run: 1 suppressed preview images.')
            ->expectsOutputToContain('Dry run: 1 suppressed sample images.')
            ->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(1, DB::table('release_imagery_disk_skips')->count());
    }

    public function test_the_requeue_command_repends_the_ledgered_releases_and_clears_their_rows(): void
    {
        $this->seedSkippedRelease();
        DB::table('releases')->insert(['id' => 2, 'guid' => 'guid-2', 'categories_id' => 6010, 'haspreview' => 0]);

        $this->artisan('releases:requeue-imagery-disk-skips', ['--apply' => true])
            ->expectsOutputToContain('Re-queued 1 release.')
            ->assertExitCode(0);

        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 2)->value('haspreview'), 'Only ledgered releases are touched.');
        $this->assertSame(0, DB::table('release_imagery_disk_skips')->count());
    }

    public function test_the_requeue_command_refuses_contradictory_modes(): void
    {
        $this->artisan('releases:requeue-imagery-disk-skips', ['--dry-run' => true, '--apply' => true])
            ->expectsOutputToContain('Choose either --dry-run or --apply, not both.')
            ->assertExitCode(1);
    }

    public function test_the_requeue_command_honors_a_limit(): void
    {
        $this->seedSkippedRelease();
        DB::table('releases')->insert(['id' => 2, 'guid' => 'guid-2', 'categories_id' => 6010, 'haspreview' => 0]);
        DB::table('release_imagery_disk_skips')->insert(['releases_id' => 2, 'suppressed' => 'sample']);

        $this->artisan('releases:requeue-imagery-disk-skips', ['--apply' => true, '--limit' => 1])
            ->expectsOutputToContain('Re-queued 1 release.')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('release_imagery_disk_skips')->count());
        $this->assertSame(2, (int) DB::table('release_imagery_disk_skips')->value('releases_id'));
    }

    private function seedSkippedRelease(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'haspreview' => 0]);
        DB::table('release_imagery_disk_skips')->insert([
            'releases_id' => 1,
            'suppressed' => 'sample,preview',
        ]);
    }

    private function makeContext(): ReleaseProcessingContext
    {
        $context = new ReleaseProcessingContext(new Release([
            'id' => 1,
            'guid' => 'guid-1',
        ]));
        $context->nzbHasCompressedFile = false;
        $context->releaseHasPassword = false;

        return $context;
    }

    private function makeManager(): ReleaseFileManager
    {
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $releaseImage->imgSavePath = '/nonexistent/img/';
        $releaseImage->vidSavePath = '/nonexistent/vid/';
        $releaseImage->jpgSavePath = '/nonexistent/jpg/';
        $releaseImage->shouldReceive('imageExists')->andReturn(false);
        $releaseImage->shouldReceive('videoArtifactPath')->andReturn(null);

        return new ReleaseFileManager(
            $this->makeConfig(),
            $releaseImage,
            Mockery::mock(NfoService::class),
            Mockery::mock(NzbService::class),
            Mockery::mock(NameFixingService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId): void {},
            ),
        );
    }
}
