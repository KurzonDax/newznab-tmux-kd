<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\AdditionalProcessing\AudioTagExtractor;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseClaimant;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AudioProcessing\AudioFetcher;
use App\Services\AudioProcessing\AudioPreviewEncoder;
use App\Services\AudioProcessing\AudioProcessingConfiguration;
use App\Services\AudioProcessing\AudioProcessingOrchestrator;
use App\Services\AudioProcessing\AudioReleaseProcessor;
use App\Services\AudioProcessing\AudioSourceSelector;
use App\Services\AudioProcessing\AudioTagRenamer;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\ReleaseExtraService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\TempWorkspaceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AudioProcessingOrchestratorTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'releaseprocessingtimeout' => '120',
            'maxpptimeoutcount' => '3',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config(['nntmux_settings.check_passworded_rars' => false]);
        $this->createSchema();
        (new ReflectionProperty(ReleaseClaimant::class, 'supportsClaims'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ReleaseClaimant::class, 'supportsClaims'))->setValue(null, null);
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_it_logs_failure_reason_counts_and_debug_settlement_for_each_release(): void
    {
        Log::spy();
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.sounds.lossless',
            'forced_root_categories_id' => null,
        ]);
        $this->seedRelease(1);
        $this->seedRelease(2);

        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->twice()->andReturn([
            'contents' => [],
            'error' => 'The NZB could not be decoded.',
        ]);
        $processor = $this->processor($parser);
        $orchestrator = new AudioProcessingOrchestrator(
            $this->config(),
            $processor,
            new TempWorkspaceService,
        );

        try {
            $results = $orchestrator->start('a', 'worker-1');
        } finally {
            $orchestrator->finish();
        }

        $this->assertCount(2, $results);
        Log::shouldHaveReceived('debug')
            ->twice()
            ->with(
                'Audio release settled',
                Mockery::on(static fn (array $context): bool => in_array($context['release_id'] ?? null, [1, 2], true)
                    && ($context['outcome'] ?? null) === 'failed'
                    && ($context['reason'] ?? null) === 'The NZB could not be decoded.'),
            );
        Log::shouldHaveReceived('info')
            ->once()
            ->with(
                'Audio postprocessing run finished',
                Mockery::on(static fn (array $context): bool => ($context['outcomes'] ?? null) === ['failed' => 2]
                    && ($context['reasons'] ?? null) === ['The NZB could not be decoded.' => 2]),
            );
    }

    private function createSchema(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->char('leftguid', 1);
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('fromname')->nullable();
            $table->integer('proc_pp')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('predb_id')->default(0);
            $table->integer('pp_timeout_count')->default(0);
            $table->integer('passwordstatus');
            $table->integer('haspreview');
            $table->integer('nzbstatus');
            $table->dateTime('postdate')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
        });
    }

    private function seedRelease(int $id): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => 'audio-guid-'.$id,
            'leftguid' => 'a',
            'name' => 'Album '.$id,
            'searchname' => 'Album '.$id,
            'fromname' => 'poster@example.test',
            'proc_pp' => 0,
            'size' => 5 * 1024 * 1024,
            'groups_id' => 1,
            'categories_id' => Category::MUSIC_LOSSLESS,
            'predb_id' => 0,
            'pp_timeout_count' => 0,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'postdate' => '2026-08-23 12:00:00',
            'additional_pp_claimed_at' => null,
            'additional_pp_claim_token' => null,
        ]);
    }

    private function processor(NzbContentParser $parser): AudioReleaseProcessor
    {
        /** @var AudioFetcher $fetcher */
        $fetcher = (new ReflectionClass(AudioFetcher::class))->newInstanceWithoutConstructor();
        /** @var AudioPreviewEncoder $encoder */
        $encoder = (new ReflectionClass(AudioPreviewEncoder::class))->newInstanceWithoutConstructor();
        /** @var AudioTagRenamer $renamer */
        $renamer = (new ReflectionClass(AudioTagRenamer::class))->newInstanceWithoutConstructor();
        /** @var ReleaseExtraService $releaseExtra */
        $releaseExtra = (new ReflectionClass(ReleaseExtraService::class))->newInstanceWithoutConstructor();
        /** @var MediaInfoRefinementService $mediaInfoRefinement */
        $mediaInfoRefinement = (new ReflectionClass(MediaInfoRefinementService::class))->newInstanceWithoutConstructor();

        return new AudioReleaseProcessor(
            $parser,
            new AudioSourceSelector,
            $fetcher,
            $encoder,
            new AudioTagExtractor,
            $renamer,
            $releaseExtra,
            $mediaInfoRefinement,
            new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
                static function (): void {},
                coalesce: false,
            ),
            new PreviewGenerationPolicy,
        );
    }

    private function config(): AudioProcessingConfiguration
    {
        $reflection = new ReflectionClass(AudioProcessingConfiguration::class);
        /** @var AudioProcessingConfiguration $config */
        $config = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'queryLimit' => 25,
            'maxSizeBytes' => 100 * 1024 * 1024,
            'tmpUnrarPath' => $this->makeTempDirectory('audio-orchestrator'),
            'debugMode' => true,
        ] as $property => $value) {
            (new ReflectionProperty(AudioProcessingConfiguration::class, $property))->setValue($config, $value);
        }

        return $config;
    }
}
