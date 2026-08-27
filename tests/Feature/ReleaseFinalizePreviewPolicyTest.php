<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\PreviewGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class ReleaseFinalizePreviewPolicyTest extends TestCase
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

        DB::table('root_categories')->insert([
            ['id' => 3000, 'title' => 'Audio', 'generate_previews' => 1],
            ['id' => 6000, 'title' => 'XXX', 'generate_previews' => 0],
        ]);

        DB::table('categories')->insert([
            ['id' => 3010, 'root_categories_id' => 3000],
            ['id' => 6010, 'root_categories_id' => 6000],
        ]);

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

    public function test_finalize_records_the_skipped_by_policy_sentinel(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'haspreview' => -1]);

        $context = $this->makeContext();
        $context->previewGenerationSkippedByPolicy = true;

        $this->makeManager()->finalizeRelease($context, false);

        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY, (int) $row->haspreview);
        $this->assertSame(0, (int) $row->passwordstatus, 'Password state is written exactly as today.');
    }

    public function test_finalize_records_zero_when_generation_was_allowed_but_produced_nothing(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 3010, 'haspreview' => -1]);

        $this->makeManager()->finalizeRelease($this->makeContext(), false);

        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    public function test_finalize_keeps_an_existing_preview_visible_even_when_skipped_by_policy(): void
    {
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 6010, 'haspreview' => -1]);

        $context = $this->makeContext();
        $context->previewGenerationSkippedByPolicy = true;

        $this->makeManager(thumbExists: true)->finalizeRelease($context, false);

        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    public function test_finalize_leaves_a_mid_run_recategorized_release_pending_instead_of_stamping_the_sentinel(): void
    {
        // Skip was decided against the disabled root, but the audio-mediainfo
        // rename moved the release into an enabled root during the same run.
        DB::table('releases')->insert(['id' => 1, 'guid' => 'guid-1', 'categories_id' => 3010, 'haspreview' => -1]);

        config([
            'nntmux_settings.check_passworded_rars' => false,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);

        $context = $this->makeContext();
        $context->previewGenerationSkippedByPolicy = true;

        $this->makeManager()->finalizeRelease($context, false);

        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $row->haspreview, 'Owed a full re-run, so it stays pending.');
        $this->assertSame(0, (int) $row->passwordstatus, 'Pending password sentinel is mode-aware.');
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

    private function makeManager(bool $thumbExists = false): ReleaseFileManager
    {
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $releaseImage->imgSavePath = '/nonexistent/img/';
        $releaseImage->vidSavePath = '/nonexistent/vid/';
        $releaseImage->jpgSavePath = '/nonexistent/jpg/';
        $releaseImage->shouldReceive('imageExists')
            ->with('/nonexistent/img/', 'guid-1_thumb')->andReturn($thumbExists);
        $releaseImage->shouldReceive('imageExists')
            ->with('/nonexistent/jpg/', 'guid-1_thumb')->andReturn(false);
        $releaseImage->shouldReceive('videoArtifactPath')
            ->with('guid-1')->andReturn(null);

        $synchronized = [];

        return new ReleaseFileManager(
            $this->makeConfig(),
            $releaseImage,
            Mockery::mock(NfoService::class),
            Mockery::mock(NzbService::class),
            Mockery::mock(NameFixingService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (int $releaseId) use (&$synchronized): void {
                    $synchronized[] = $releaseId;
                },
            ),
        );
    }
}
