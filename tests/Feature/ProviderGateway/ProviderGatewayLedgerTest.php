<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\ObfuscationRecovery\RecoveryCatalog;
use App\Services\ProviderGateway\ProviderGateway;
use App\Services\ProviderGateway\ProviderOutcome;
use App\Services\TmdbClient;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use DariusIII\TVMaze\TVMaze;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The recovery ledger keeps the rows it wrote before the gateway: one per attempt, a
 * short-circuit written as the transport failure a connection error writes, and TVMaze's
 * `legacy_http` rows.
 */
final class ProviderGatewayLedgerTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', fn (Blueprint $table) => $table->increments('id'));
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid')->unique();
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture']);
        DB::table('obfuscation_recovery_publications')->insert([
            'identity' => str_repeat('a', 64), 'index_identity' => str_repeat('b', 64), 'index_message_id' => 'index@local',
            'set_id' => str_repeat('c', 32), 'plan_digest' => str_repeat('d', 64), 'collection_projection' => str_repeat('e', 20),
            'releases_id' => 1, 'guid' => 'fixture', 'profile' => 'nyuu-media-v1', 'group_name' => 'alt.fixture', 'source_epoch' => 'epoch',
            'state' => 'published', 'ordering_mode' => 'embedded_timestamp', 'inventory_scope' => 'single',
            'protected_files' => 1, 'planned_files' => 2, 'planned_parts' => 4, 'sealed_plan' => '{}', 'manifest_digest' => str_repeat('f', 64),
        ]);
        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        config(['tmdb.api_key' => 'fixture', 'tmdb.retry_times' => 2, 'tmdb.retry_delay' => 0, 'nntmux.echocli' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function every_attempt_and_an_open_breaker_short_circuit_write_the_rows_they_wrote_before(): void
    {
        Http::fake(['api.themoviedb.org/*' => Http::sequence()->push([], 503)->push(['id' => 123], 200)]);
        $client = new TmdbClient;

        RecoveryCatalog::run(1, fn (): ?array => $client->getMovie(123));
        foreach (range(1, 3) as $failure) {
            app(ProviderGateway::class)->report('tmdb', ProviderOutcome::Unavailable);
        }
        $this->assertNull(RecoveryCatalog::run(1, fn (): ?array => $client->getMovie(456)));

        $this->assertSame([
            ['api.themoviedb.org', 'http', 'http_failure', 503],
            ['api.themoviedb.org', 'http', 'success', 200],
            ['api.themoviedb.org', 'http', 'transport_failure', null],
        ], $this->rows());
        Http::assertSentCount(2);
    }

    #[Test]
    public function tvmaze_calls_keep_their_legacy_rows(): void
    {
        $provider = new TvMazeProvider;
        $client = Mockery::mock(TVMaze::class);
        $client->shouldReceive('singleSearchAkas')->once()->andReturnFalse();
        $client->shouldReceive('search')->once()->andThrow(new RuntimeException('curl failed'));
        $client->shouldReceive('getEpisodeByNumber')->once()->andReturn((object) ['id' => 1]);
        $provider->client = $client;

        RecoveryCatalog::run(1, function () use ($provider): void {
            $provider->getShowInfo('Example Show');
            $provider->getEpisodeInfo(7, 1, 2);
        });

        $this->assertSame([
            ['api.tvmaze.com', 'legacy_http', 'unavailable', null],
            ['api.tvmaze.com', 'legacy_http', 'transport_failure', null],
            ['api.tvmaze.com', 'legacy_http', 'response_received', null],
        ], $this->rows());
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: int|null}> */
    private function rows(): array
    {
        return DB::table('obfuscation_recovery_catalog')->orderBy('id')->get()
            ->map(static fn (object $row): array => [$row->provider, $row->kind, $row->outcome, $row->http_status === null ? null : (int) $row->http_status])
            ->all();
    }
}
