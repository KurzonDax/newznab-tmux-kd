<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryCatalog;
use App\Services\TmdbClient;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryCatalogTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', fn (Blueprint $table) => $table->increments('id'));
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture']);
        DB::table('obfuscation_recovery_publications')->insert([
            'identity' => str_repeat('a', 64), 'index_identity' => str_repeat('b', 64), 'index_message_id' => 'index@local',
            'set_id' => str_repeat('c', 32), 'plan_digest' => str_repeat('d', 64), 'collection_projection' => str_repeat('e', 20),
            'releases_id' => 1, 'guid' => 'fixture', 'profile' => 'nyuu-media-v1', 'group_name' => 'alt.fixture', 'source_epoch' => 'epoch',
            'state' => 'published', 'ordering_mode' => 'embedded_timestamp', 'inventory_scope' => 'single',
            'protected_files' => 1, 'planned_files' => 2, 'planned_parts' => 4, 'sealed_plan' => '{}', 'manifest_digest' => str_repeat('f', 64),
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_actual_catalog_retries_and_cache_reads_are_separate_from_nntp_and_scope_is_restored(): void
    {
        config(['tmdb.api_key' => 'fixture', 'tmdb.retry_times' => 2, 'tmdb.retry_delay' => 0]);
        Http::fake(['api.themoviedb.org/*' => Http::sequence()->push([], 503)->push(['id' => 123], 200)->push(['id' => 456], 200)]);
        $client = new TmdbClient;
        $result = RecoveryCatalog::run(1, function () use ($client) {
            Cache::put('tmdb_movie_fixture', ['id' => 123], 60);
            Cache::get('tmdb_movie_fixture');

            return $client->getMovie(123);
        });
        $this->assertSame(123, $result['id']);
        $requests = DB::table('obfuscation_recovery_catalog')->where('kind', 'http')->orderBy('id')->get();
        $this->assertSame(['http_failure', 'success'], $requests->pluck('outcome')->all());
        $this->assertSame([503, 200], $requests->pluck('http_status')->map(intval(...))->all());
        $this->assertSame(1, DB::table('obfuscation_recovery_catalog')->where('kind', 'cache')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        try {
            RecoveryCatalog::run(1, fn () => throw new \RuntimeException('fixture_failure'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(456, $client->getMovie(456)['id']);
        $this->assertSame(3, DB::table('obfuscation_recovery_catalog')->count());
        $this->travel(31)->days();
        $this->assertSame(3, RecoveryCatalog::compact());
        $this->assertSame(3, (int) DB::table('obfuscation_recovery_catalog')->sum('requests'));
        $this->assertSame(0, RecoveryCatalog::compact());
        Cache::forget('tmdb_movie_fixture');
    }

    public function test_cache_result_accounting_excludes_throttles_and_deduplicates_has_then_get(): void
    {
        $keys = ['trakt_throttle_fixture', 'imdb_rate_limit_fixture', 'imdb_movie_fixture', 'fanarttv_movie_fixture'];
        foreach ($keys as $key) {
            Cache::put($key, ['id' => 123], 60);
        }
        RecoveryCatalog::run(1, function () use ($keys): void {
            foreach ($keys as $key) {
                $this->assertTrue(Cache::has($key));
                $this->assertSame(['id' => 123], Cache::get($key));
            }
        });
        $this->assertSame(['imdb', 'fanarttv'], DB::table('obfuscation_recovery_catalog')->orderBy('id')->pluck('provider')->all());
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_catalog')->sum('requests'));
        RecoveryCatalog::run(1, fn () => Cache::get('imdb_movie_fixture'));
        $this->assertSame(3, (int) DB::table('obfuscation_recovery_catalog')->sum('requests'));
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    public function test_interrupted_catalog_requests_compact_with_unknown_outcomes_and_sizes(): void
    {
        $handler = RecoveryCatalog::middleware()(fn () => new Promise);
        RecoveryCatalog::run(1, fn () => $handler(new Request('GET', 'https://catalog.example/fixture'), []));
        $this->assertSame('started', DB::table('obfuscation_recovery_catalog')->value('outcome'));
        $this->assertNull(DB::table('obfuscation_recovery_catalog')->value('finished_at'));
        $this->assertSame(0, RecoveryCatalog::compact());
        $this->travel(31)->days();
        $this->assertSame(1, RecoveryCatalog::compact());
        $aggregate = DB::table('obfuscation_recovery_catalog')->sole();
        $this->assertSame('interrupted_unknown', $aggregate->outcome);
        $this->assertSame(1, (int) $aggregate->requests);
        $this->assertSame(1, (int) $aggregate->unknown_response_sizes);
        $this->assertSame(0, (int) $aggregate->response_bytes);
        $this->assertNotNull($aggregate->aggregate_digest);
        $this->assertSame(0, RecoveryCatalog::compact());
    }

    public function test_transport_failures_preserve_request_accounting_and_clear_release_scope(): void
    {
        Http::fake(['catalog.example/failure' => Http::failedConnection(), 'catalog.example/ordinary' => Http::response('ordinary')]);
        try {
            RecoveryCatalog::run(1, fn () => Http::get('https://catalog.example/failure'));
            $this->fail('Expected transport failure');
        } catch (ConnectionException) {
            $this->assertSame('transport_failure', DB::table('obfuscation_recovery_catalog')->value('outcome'));
            $this->assertSame(1, (int) DB::table('obfuscation_recovery_catalog')->value('unknown_response_sizes'));
        }
        Http::get('https://catalog.example/ordinary');
        $this->assertSame(1, DB::table('obfuscation_recovery_catalog')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }
}
