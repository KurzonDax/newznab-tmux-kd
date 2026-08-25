<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\ReleaseRepair\EvidenceChangedTransition;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\Search\Drivers\ElasticSearchDriver;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ElasticsearchReleaseIndexFailureTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private ElasticSearchDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        Schema::create('search_index_failures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->string('operation', 32)->default('upsert');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->integer('totalpart')->default(0);
            $table->double('completion')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->integer('proc_nfo')->default(0);
            $table->integer('proc_files')->default(0);
            $table->integer('proc_srr')->default(0);
            $table->integer('proc_crc32')->default(0);
            $table->integer('proc_uid')->default(0);
            $table->integer('proc_hash16k')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('proc_srrdb')->default(0);
            $table->integer('proc_xxx')->default(0);
            $table->integer('proc_media_movie')->default(0);
        });
        Schema::create('video_data', function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id');
        });
        Schema::create('audio_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
        });

        $this->driver = new ElasticSearchDriver(['indexes' => ['releases' => 'releases']]);
        $reflection = new ReflectionClass(ElasticSearchDriver::class);
        $reflection->getProperty('availabilityCache')->setValue(null, false);
        $reflection->getProperty('availabilityCacheTime')->setValue(null, time());
    }

    protected function tearDown(): void
    {
        $this->driver->resetConnection();
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    #[Test]
    public function unavailable_elasticsearch_updates_enter_the_existing_repair_queue(): void
    {
        $this->driver->updateRelease(42);

        $failure = DB::table('search_index_failures')->where('release_id', 42)->first();
        $this->assertNotNull($failure);
        $this->assertSame('upsert', $failure->operation);
        $this->assertSame(1, (int) $failure->attempts);
        $this->assertSame('updateRelease_unavailable', $failure->last_error);
        $this->assertNotNull($failure->next_attempt_at);
        $this->assertNull($failure->resolved_at);
    }

    #[Test]
    public function unavailable_elasticsearch_inserts_enter_the_existing_repair_queue(): void
    {
        $this->driver->insertRelease(['id' => 43]);

        $failure = DB::table('search_index_failures')->where('release_id', 43)->first();
        $this->assertNotNull($failure);
        $this->assertSame('upsert', $failure->operation);
        $this->assertSame('insertRelease_unavailable', $failure->last_error);
    }

    #[Test]
    public function unavailable_elasticsearch_deletes_enter_the_queue_as_delete_operations(): void
    {
        $this->driver->deleteReleases([44, 45]);

        $failures = DB::table('search_index_failures')->orderBy('release_id')->get();
        $this->assertSame([44, 45], $failures->pluck('release_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame(['delete', 'delete'], $failures->pluck('operation')->all());
        $this->assertSame(['deleteReleases_unavailable', 'deleteReleases_unavailable'], $failures->pluck('last_error')->all());
    }

    #[Test]
    public function successful_elasticsearch_deletes_resolve_existing_failures(): void
    {
        $this->insertFailure(46);
        $this->useElasticResponse(new Response(200, $this->elasticHeaders(), json_encode([
            'errors' => false,
            'items' => [
                ['delete' => ['_id' => '46', 'status' => 200]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->driver->deleteReleases([46]);

        $failure = DB::table('search_index_failures')->where('release_id', 46)->first();
        $this->assertNotNull($failure->resolved_at);
        $this->assertNull($failure->next_attempt_at);
    }

    #[Test]
    public function already_missing_elasticsearch_deletes_resolve_existing_failures(): void
    {
        $this->insertFailure(47);
        $this->useElasticResponse(new Response(200, $this->elasticHeaders(), json_encode([
            'errors' => true,
            'items' => [
                ['delete' => [
                    '_id' => '47',
                    'status' => 404,
                    'error' => ['type' => 'document_missing_exception', 'reason' => 'missing'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->driver->deleteReleases([47]);

        $failure = DB::table('search_index_failures')->where('release_id', 47)->first();
        $this->assertNotNull($failure->resolved_at);
        $this->assertNull($failure->next_attempt_at);
    }

    #[Test]
    public function rejected_bulk_delete_items_remain_in_the_repair_queue(): void
    {
        $this->insertFailure(48);
        $this->useElasticResponse(new Response(200, $this->elasticHeaders(), json_encode([
            'errors' => true,
            'items' => [
                ['delete' => [
                    '_id' => '48',
                    'status' => 429,
                    'error' => ['type' => 'es_rejected_execution_exception', 'reason' => 'busy'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->driver->deleteReleases([48]);

        $failure = DB::table('search_index_failures')->where('release_id', 48)->first();
        $this->assertNull($failure->resolved_at);
        $this->assertSame(2, (int) $failure->attempts);
        $this->assertStringContainsString('es_rejected_execution_exception', $failure->last_error);
    }

    #[Test]
    public function a_failed_recovery_sync_is_retried_by_the_existing_repair_command(): void
    {
        config(['search.default' => 'elasticsearch']);
        // ReleaseFactory requires the full production schema and fires search observers;
        // this fixture intentionally isolates the recovery transition and failure ledger.
        DB::table('releases')->insert([
            'id' => 49,
            'guid' => 'recovery-release',
            'completion' => 50.0,
            'totalpart' => 0,
            'nfostatus' => 0,
        ]);
        DB::table('video_data')->insert(['releases_id' => 49]);
        $candidate = Release::query()->select(['id', 'guid', 'completion', 'haspreview'])->findOrFail(49);
        $document = NzbRepairDocument::load(
            '<?xml version="1.0"?><nzb><file subject="Recovery (1/1)"><segments><segment bytes="100" number="1">part@example.test</segment></segments></file></nzb>',
        );
        $this->assertNotNull($document);

        (new EvidenceChangedTransition)->apply($candidate, $document);

        $failure = DB::table('search_index_failures')->where('release_id', 49)->first();
        $this->assertNotNull($failure);
        $this->assertSame('updateRelease_unavailable', $failure->last_error);
        DB::table('search_index_failures')->where('release_id', 49)->update([
            'next_attempt_at' => now()->subSecond(),
        ]);

        Search::spy();
        $this->artisan('nntmux:search-repair')->assertSuccessful();

        Search::shouldHaveReceived('updateRelease')->once()->with(49);
    }

    private function insertFailure(int $releaseId): void
    {
        DB::table('search_index_failures')->insert([
            'release_id' => $releaseId,
            'operation' => 'delete',
            'attempts' => 1,
            'last_error' => 'previous failure',
            'next_attempt_at' => now(),
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function useElasticResponse(Response $response): void
    {
        $client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->setHttpClient(new GuzzleClient([
                'handler' => HandlerStack::create(new MockHandler([$response])),
            ]))
            ->build();
        $reflection = new ReflectionClass(ElasticSearchDriver::class);
        $reflection->getProperty('client')->setValue(null, $client);
        $reflection->getProperty('availabilityCache')->setValue(null, true);
        $reflection->getProperty('availabilityCacheTime')->setValue(null, time());
    }

    /** @return array<string, string> */
    private function elasticHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Elastic-Product' => 'Elasticsearch',
        ];
    }
}
