<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** IGDB refuses a search combined with a sort (406), so the fuzzy strategy sends none. */
final class IgdbFuzzySearchTest extends TestCase
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

    /** @return array<string, array{\Closure(IGDBService): ?Game}> */
    public static function searches(): array
    {
        return [
            'console search' => [static fn (IGDBService $igdb): ?Game => $igdb->searchConsole('Halo Reach', 'X360')],
            'PC search' => [static fn (IGDBService $igdb): ?Game => $igdb->search('Halo Reach')],
        ];
    }

    /** @param \Closure(IGDBService): ?Game $search */
    #[Test]
    #[DataProvider('searches')]
    public function the_fuzzy_search_sends_no_sort_and_the_exact_search_keeps_its_sort(\Closure $search): void
    {
        $this->fakeIgdbGames(Http::response([]));

        $this->assertNull($search(new IGDBService));

        $queries = $this->sentGameQueries();
        $fuzzy = array_filter($queries, static fn (string $body): bool => str_contains($body, 'search "'));
        $exact = array_filter($queries, static fn (string $body): bool => ! str_contains($body, 'search "'));

        $this->assertNotEmpty($fuzzy);
        $this->assertNotEmpty($exact);

        foreach ($fuzzy as $body) {
            $this->assertStringNotContainsString('sort ', $body);
        }

        foreach ($exact as $body) {
            $this->assertStringContainsString('sort aggregated_rating_count desc;', $body);
        }
    }

    #[Test]
    public function a_game_the_exact_search_misses_is_found_by_the_fuzzy_search(): void
    {
        $title = 'SOCOM: U.S. Navy SEALs Fireteam Bravo 2';

        // Answers as IGDB does: a search with a sort is refused with an empty 406.
        $this->fakeIgdbGames(static fn (Request $request) => match (true) {
            ! str_contains($request->body(), 'search "') => Http::response([]),
            str_contains($request->body(), 'sort ') => Http::response('', 406),
            default => Http::response([
                ['id' => 1, 'name' => $title, 'platforms' => [['name' => 'PlayStation Portable', 'abbreviation' => 'PSP']]],
            ]),
        });

        $game = (new IGDBService)->searchConsole($title, 'PSP');

        $this->assertInstanceOf(Game::class, $game);
        $this->assertSame($title, $game->name);
    }

    private function fakeIgdbGames(mixed $games): void
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
}
