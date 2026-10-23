<?php

declare(strict_types=1);

namespace Tests\Feature\ProviderGateway;

use App\Services\IGDBService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** IGDB searches IGDB could not answer are never remembered as "no such game". */
final class GamesProviderGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        config([
            'igdb.base_url' => 'https://api.igdb.test/v4',
            'igdb.token_url' => 'https://id.twitch.test/oauth2/token',
            'igdb.credentials.client_id' => 'client-id',
            'igdb.credentials.client_secret' => 'client-secret',
            'igdb.cache_lifetime' => 0,
        ]);
    }

    #[Test]
    public function a_search_refused_by_the_spent_budget_sends_nothing_and_is_not_cached(): void
    {
        $this->fakeIgdb(Http::response([]));
        foreach (range(1, 4) as $request) {
            RateLimiter::hit('igdb_api_rate_limit', 60);
        }

        $this->assertNull((new IGDBService)->searchConsole('Halo', 'X360'));

        $this->assertSame([], $this->sentGameQueries());
        $this->assertFalse(Cache::has($this->failedConsoleSearchKey('Halo', 'X360')));
    }

    #[Test]
    public function a_rejected_406_search_is_not_cached(): void
    {
        $this->fakeIgdb(Http::response('Not Acceptable', 406));

        $this->assertNull((new IGDBService)->searchConsole('Halo', 'X360'));

        $this->assertFalse(Cache::has($this->failedConsoleSearchKey('Halo', 'X360')));
    }

    #[Test]
    public function a_search_igdb_answered_with_nothing_is_kept_an_hour(): void
    {
        $this->fakeIgdb(Http::response([]));

        $this->assertNull((new IGDBService)->searchConsole('Halo', 'X360'));

        $this->assertTrue(Cache::has($this->failedConsoleSearchKey('Halo', 'X360')));
        $this->travel(3_601)->seconds();
        $this->assertFalse(Cache::has($this->failedConsoleSearchKey('Halo', 'X360')));
    }

    private function fakeIgdb(mixed $games): void
    {
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.igdb.test/v4/games' => $games,
        ]);
    }

    /** @return list<string> */
    private function sentGameQueries(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->url() === 'https://api.igdb.test/v4/games')
            ->map(static fn (array $pair): string => $pair[0]->body())
            ->values()
            ->all();
    }

    private function failedConsoleSearchKey(string $title, string $platformHint): string
    {
        return 'igdb_console_search_failed:igdb_console_search:v3:'.md5(mb_strtolower($title.'|'.$platformHint));
    }
}
