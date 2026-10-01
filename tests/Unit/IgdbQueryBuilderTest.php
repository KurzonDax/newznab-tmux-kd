<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IgdbQueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'igdb.base_url' => 'https://api.igdb.test/v4',
            'igdb.token_url' => 'https://id.twitch.test/oauth2/token',
            'igdb.credentials.client_id' => 'client-id',
            'igdb.credentials.client_secret' => 'client-secret',
            'igdb.cache_lifetime' => 0,
        ]);
    }

    public function test_game_query_builder_uses_local_client_and_hydrates_nested_results(): void
    {
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://api.igdb.test/v4/games' => Http::response([
                [
                    'id' => 42,
                    'name' => 'Halo',
                    'first_release_date' => 978307200,
                    'cover' => [
                        'url' => '//images.igdb.com/igdb/image/upload/t_cover_big/test.jpg',
                    ],
                    'themes' => [
                        ['name' => 'Sci-Fi'],
                    ],
                    'platforms' => [6, 14],
                ],
            ]),
        ]);

        $results = Game::search('Halo')
            ->whereIn('platforms', [6, 14])
            ->with([
                'cover' => ['url'],
                'themes' => ['name'],
            ])
            ->orderByDesc('aggregated_rating_count')
            ->limit(10)
            ->get();

        $this->assertCount(1, $results);
        $game = $results->first();
        $this->assertInstanceOf(Game::class, $game);
        $this->assertSame('Halo', $game->name);
        $this->assertSame('//images.igdb.com/igdb/image/upload/t_cover_big/test.jpg', $game->cover->url);
        $this->assertSame('Sci-Fi', $game->themes[0]->name);
        $this->assertSame('2001-01-01', $game->first_release_date->format('Y-m-d'));

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.igdb.test/v4/games') {
                return false;
            }

            $body = $request->body();

            return $request->hasHeader('Client-ID', 'client-id')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && str_contains($body, 'fields *,cover.url,themes.name;')
                && str_contains($body, 'search "Halo";')
                && str_contains($body, 'where platforms = (6,14);')
                && str_contains($body, 'sort aggregated_rating_count desc;')
                && str_contains($body, 'limit 10;');
        });
    }

    public function test_igdb_service_reads_the_local_igdb_configuration(): void
    {
        $service = new IGDBService;

        $this->assertTrue($service->isConfigured());

        config([
            'igdb.credentials.client_id' => '',
        ]);

        $this->assertFalse($service->isConfigured());
    }

    public function test_igdb_service_can_build_console_data_using_platform_hint_matching(): void
    {
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://api.igdb.test/v4/games' => Http::response([
                [
                    'id' => 42,
                    'name' => 'Halo',
                    'summary' => 'Sci-fi shooter',
                    'aggregated_rating' => 94.2,
                    'age_ratings' => [
                        [
                            'organization' => ['id' => 1, 'name' => 'ESRB'],
                            'rating_category' => ['id' => 4, 'rating' => 'E10+', 'organization' => 1],
                        ],
                    ],
                    'first_release_date' => 1005782400,
                    'cover' => [
                        'image_id' => 'halo-cover',
                    ],
                    'themes' => [
                        ['name' => 'Action'],
                    ],
                    'platforms' => [
                        ['name' => 'Xbox 360', 'abbreviation' => 'X360'],
                        ['name' => 'PC (Microsoft Windows)', 'abbreviation' => 'PC'],
                    ],
                ],
            ]),
        ]);

        $service = new IGDBService;
        $game = $service->searchConsole('Halo', 'X360');

        $this->assertNotNull($game);

        $consoleData = $service->buildConsoleData($game, 'Xbox 360');

        $this->assertSame('42', $consoleData['asin']);
        $this->assertSame('Xbox 360', $consoleData['platform']);
        $this->assertSame('Action', $consoleData['consolegenre']);
        $this->assertSame('E10+', $consoleData['esrb']);
        $this->assertSame('https://images.igdb.com/igdb/image/upload/t_cover_big/halo-cover.jpg', $consoleData['coverurl']);
        $this->assertSame('2001-11-15', $consoleData['releasedate']);
    }

    public function test_the_lookup_requests_the_supported_age_rating_fields(): void
    {
        $this->fakeIgdbGames([['id' => 42, 'name' => 'Halo']]);

        $this->assertNotNull((new IGDBService)->searchConsole('Halo', 'X360'));

        $fields = $this->fieldsOf($this->sentGameQueries()[0]);

        $this->assertContains('age_ratings.organization.name', $fields);
        $this->assertContains('age_ratings.rating_category.rating', $fields);
        $this->assertNotContains('age_ratings.rating', $fields);
        $this->assertNotContains('age_ratings.category', $fields);
    }

    public function test_the_fuzzy_search_filters_on_game_type_and_requests_the_website_type(): void
    {
        $this->fakeIgdbGames([]);

        $this->assertNull((new IGDBService)->searchConsole('Halo Reach', 'X360'));

        $fuzzy = array_values(array_filter(
            $this->sentGameQueries(),
            static fn (string $body): bool => str_contains($body, 'search "'),
        ));

        $this->assertNotEmpty($fuzzy);

        foreach ($fuzzy as $body) {
            $this->assertStringContainsString('where game_type = 0;', $body);
            $this->assertStringNotContainsString('category', $this->whereOf($body));

            $fields = $this->fieldsOf($body);
            $this->assertContains('websites.type', $fields);
            $this->assertNotContains('websites.category', $fields);
        }
    }

    public function test_a_search_cached_before_the_field_change_is_not_reused(): void
    {
        $this->fakeIgdbGames([['id' => 42, 'name' => 'Halo']]);

        $staleKey = 'igdb_console_search:'.md5(mb_strtolower('Halo|X360'));
        Cache::put($staleKey, new Game(['id' => 7, 'name' => 'Stale Halo']), 86400);
        $failedConsoleKey = 'igdb_console_search:'.md5(mb_strtolower('Halo|PS3'));
        Cache::put("igdb_console_search_failed:{$failedConsoleKey}", true, 3600);
        $failedPcKey = 'igdb_search:'.md5(mb_strtolower('Halo'));
        Cache::put("igdb_search_failed:{$failedPcKey}", true, 3600);

        $service = new IGDBService;

        $this->assertSame('Halo', $service->searchConsole('Halo', 'X360')?->name);
        $this->assertNotNull($service->searchConsole('Halo', 'PS3'));
        $this->assertNotNull($service->search('Halo'));
        $this->assertCount(3, $this->sentGameQueries());
    }

    public function test_a_pegi_only_game_stores_the_pegi_rating(): void
    {
        $consoleData = $this->consoleDataFor([
            'age_ratings' => [
                $this->ageRating(2, 'PEGI', 11, '16'),
            ],
        ]);

        $this->assertSame('PEGI 16', $consoleData['esrb']);
    }

    public function test_an_esrb_rating_wins_over_a_pegi_rating_listed_before_it(): void
    {
        $consoleData = $this->consoleDataFor([
            'age_ratings' => [
                $this->ageRating(2, 'PEGI', 12, '18'),
                $this->ageRating(1, 'ESRB', 6, 'M'),
            ],
        ]);

        $this->assertSame('M', $consoleData['esrb']);
    }

    public function test_a_rating_from_another_organisation_is_ignored(): void
    {
        $consoleData = $this->consoleDataFor([
            'age_ratings' => [
                $this->ageRating(4, 'USK', 22, '16'),
            ],
        ]);

        $this->assertNull($consoleData['esrb']);
    }

    public function test_a_critic_score_is_never_stored_as_the_age_rating(): void
    {
        $consoleData = $this->consoleDataFor([
            'aggregated_rating' => 94.2,
        ]);

        $this->assertNull($consoleData['esrb']);

        foreach ($consoleData as $value) {
            $this->assertStringNotContainsString('%', (string) $value);
        }
    }

    public function test_the_pc_path_stores_the_same_age_rating(): void
    {
        $genreName = '';
        $gameData = (new IGDBService)->buildGameData(new Game([
            'id' => 7,
            'name' => 'Halo',
            'aggregated_rating' => 94.2,
            'age_ratings' => [
                $this->ageRating(2, 'PEGI', 10, '12'),
            ],
        ]), $genreName);

        $this->assertSame('PEGI 12', $gameData['esrb']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function consoleDataFor(array $attributes): array
    {
        $game = new Game(['id' => 42, 'name' => 'Halo'] + $attributes);

        return (new IGDBService)->buildConsoleData($game, 'Xbox 360');
    }

    /**
     * @return array<string, mixed>
     */
    private function ageRating(int $organizationId, string $organization, int $categoryId, string $rating): array
    {
        return [
            'id' => $categoryId * 100,
            'organization' => ['id' => $organizationId, 'name' => $organization],
            'rating_category' => ['id' => $categoryId, 'rating' => $rating, 'organization' => $organizationId],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $games
     */
    private function fakeIgdbGames(array $games): void
    {
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ]),
            'https://api.igdb.test/v4/games' => Http::response($games),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sentGameQueries(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->url() === 'https://api.igdb.test/v4/games')
            ->map(static fn (array $pair): string => $pair[0]->body())
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function fieldsOf(string $body): array
    {
        $this->assertSame(1, preg_match('/^fields ([^;]*);/', $body, $matches), $body);

        return explode(',', $matches[1]);
    }

    private function whereOf(string $body): string
    {
        return preg_match('/where ([^;]*);/', $body, $matches) === 1 ? $matches[1] : '';
    }
}
