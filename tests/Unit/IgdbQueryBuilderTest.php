<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->assertSame(['Action'], $consoleData['consolegenres']);
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
            $this->assertStringNotContainsString('%', is_array($value) ? implode(',', $value) : (string) $value);
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

    public function test_a_console_game_stores_the_earliest_date_of_the_matched_platform(): void
    {
        $consoleData = $this->consoleDataFor([
            'first_release_date' => 959860800, // 2000-06-01
            'platforms' => $this->xboxAndPcPlatforms(),
            'release_dates' => [
                ['platform' => 9, 'date' => 959860800, 'human' => 'Jun 01, 2000'],
                ['platform' => 12, 'date' => 1019736000, 'human' => 'Apr 25, 2002'],
                ['platform' => 12, 'date' => 1016107200, 'human' => 'Mar 14, 2002'],
                ['platform' => 6, 'date' => 999345600, 'human' => 'Sep 01, 2001'],
            ],
        ]);

        $this->assertSame('Xbox 360', $consoleData['platform']);
        $this->assertSame('2002-03-14', $consoleData['releasedate']);
    }

    public function test_a_console_game_without_a_dated_entry_for_its_platform_falls_back_to_the_first_release_date(): void
    {
        $consoleData = $this->consoleDataFor([
            'first_release_date' => 959860800, // 2000-06-01
            'platforms' => $this->xboxAndPcPlatforms(),
            'release_dates' => [
                ['platform' => 12, 'human' => 'TBD'],
                ['platform' => 6, 'date' => 999345600, 'human' => 'Sep 01, 2001'],
            ],
        ]);

        $this->assertSame('2000-06-01', $consoleData['releasedate']);
    }

    public function test_a_console_game_without_any_date_stores_no_date(): void
    {
        $consoleData = $this->consoleDataFor([
            'platforms' => $this->xboxAndPcPlatforms(),
        ]);

        $this->assertSame('', $consoleData['releasedate']);
    }

    public function test_the_pc_path_prefers_the_pc_entry_then_the_first_entry(): void
    {
        $genreName = '';
        $service = new IGDBService;

        $withPcEntry = $service->buildGameData(new Game([
            'id' => 7,
            'name' => 'Halo',
            'release_dates' => [
                ['platform' => 14, 'date' => 1070452800],
                ['platform' => 6, 'date' => 1076500800],
            ],
        ]), $genreName);
        $withoutPcEntry = $service->buildGameData(new Game([
            'id' => 7,
            'name' => 'Halo',
            'first_release_date' => 959860800,
            'release_dates' => [
                ['platform' => 14, 'date' => 1070452800],
            ],
        ]), $genreName);

        $this->assertSame('2004-02-11', $withPcEntry['releasedate']);
        $this->assertSame('2003-12-03', $withoutPcEntry['releasedate']);
    }

    public function test_the_pc_path_stores_no_date_when_igdb_has_none(): void
    {
        $genreName = '';
        $gameData = (new IGDBService)->buildGameData(new Game([
            'id' => 7,
            'name' => 'Halo',
        ]), $genreName);

        $this->assertSame('', $gameData['releasedate']);
    }

    public function test_console_data_keeps_the_storyline_scores_website_companies_modes_and_perspectives(): void
    {
        $this->fakeIgdbCompanies();

        $consoleData = $this->consoleDataFor($this->fullDetails($this->expandedCompanies()));

        $this->assertSame('A long storyline.', $consoleData['storyline']);
        $this->assertSame(92, $consoleData['critic_score']);
        $this->assertSame(78, $consoleData['user_score']);
        $this->assertSame('https://example.test/a', $consoleData['website']);
        $this->assertSame([['igdb_id' => 101, 'name' => 'Studio A'], ['igdb_id' => 103, 'name' => 'Studio C']], $consoleData['developers']);
        $this->assertSame([['igdb_id' => 102, 'name' => 'Label B'], ['igdb_id' => 103, 'name' => 'Studio C']], $consoleData['publishers']);
        $this->assertSame([['igdb_id' => 1, 'name' => 'Single player'], ['igdb_id' => 2, 'name' => 'Multiplayer']], $consoleData['game_modes']);
        $this->assertSame([['igdb_id' => 4, 'name' => 'Third person']], $consoleData['player_perspectives']);
        $this->assertSame('Label B,Studio C', $consoleData['publisher']);
        $this->assertSame([], $this->sentCompanyQueries());
    }

    public function test_console_data_from_an_answer_cached_with_bare_company_ids_asks_once_per_company(): void
    {
        $this->fakeIgdbCompanies();

        $consoleData = $this->consoleDataFor($this->fullDetails([
            ['id' => 1, 'company' => 101, 'developer' => true, 'publisher' => false],
            ['id' => 2, 'company' => 102, 'developer' => false, 'publisher' => true],
            ['id' => 3, 'company' => 103, 'developer' => true, 'publisher' => true],
            ['id' => 4, 'company' => 104, 'developer' => false, 'publisher' => false],
            ['id' => 5, 'company' => 101, 'developer' => true, 'publisher' => false],
        ]));

        $this->assertSame([['igdb_id' => 101, 'name' => 'Studio A'], ['igdb_id' => 103, 'name' => 'Studio C']], $consoleData['developers']);
        $this->assertSame([['igdb_id' => 102, 'name' => 'Label B'], ['igdb_id' => 103, 'name' => 'Studio C']], $consoleData['publishers']);
        $this->assertSame('Label B,Studio C', $consoleData['publisher']);

        $asked = array_map(fn (string $body): string => $this->whereOf($body), $this->sentCompanyQueries());
        sort($asked);
        $this->assertSame(['id = 101', 'id = 102', 'id = 103'], $asked);
    }

    public function test_console_data_for_a_game_without_the_details_is_empty(): void
    {
        $consoleData = $this->consoleDataFor([]);

        $this->assertNull($consoleData['storyline']);
        $this->assertNull($consoleData['critic_score']);
        $this->assertNull($consoleData['user_score']);
        $this->assertNull($consoleData['website']);
        $this->assertSame([], $consoleData['developers']);
        $this->assertSame([], $consoleData['publishers']);
        $this->assertSame([], $consoleData['game_modes']);
        $this->assertSame([], $consoleData['player_perspectives']);
        $this->assertSame('Unknown', $consoleData['publisher']);
        $this->assertSame('42', $consoleData['asin']);
        $this->assertSame('Halo', $consoleData['title']);
    }

    public function test_console_data_leaves_out_a_blank_storyline_and_an_overlong_website(): void
    {
        $consoleData = $this->consoleDataFor([
            'storyline' => "  \n ",
            'websites' => [['id' => 1, 'type' => 1, 'url' => 'https://example.test/'.str_repeat('a', 1000)]],
            'game_modes' => [['id' => 1, 'name' => ' '], ['id' => 2, 'name' => 'Co-operative'], ['id' => 3, 'name' => 'Co-operative']],
        ]);

        $this->assertNull($consoleData['storyline']);
        $this->assertNull($consoleData['website']);
        $this->assertSame([['igdb_id' => 2, 'name' => 'Co-operative']], $consoleData['game_modes']);
    }

    public function test_the_pc_path_reads_expanded_company_names_without_asking_for_them(): void
    {
        $this->fakeIgdbCompanies();
        $genreName = '';

        $gameData = (new IGDBService)->buildGameData(new Game(['id' => 42, 'name' => 'Halo', 'involved_companies' => $this->expandedCompanies()]), $genreName);

        $this->assertSame('Label B, Studio C', $gameData['publisher']);
        $this->assertSame('Studio A, Studio C, Studio A', $gameData['developer']);
        $this->assertSame([], $this->sentCompanyQueries());
    }

    public function test_the_pc_path_asks_once_per_company_for_bare_company_ids(): void
    {
        $this->fakeIgdbCompanies();
        $genreName = '';

        $gameData = (new IGDBService)->buildGameData(new Game(['id' => 42, 'name' => 'Halo', 'involved_companies' => [
            ['id' => 1, 'company' => 101, 'developer' => true, 'publisher' => false],
            ['id' => 3, 'company' => 103, 'developer' => true, 'publisher' => true],
            ['id' => 5, 'company' => 101, 'developer' => true, 'publisher' => false],
        ]]), $genreName);

        $this->assertSame('Studio C', $gameData['publisher']);
        $this->assertSame('Studio A, Studio C, Studio A', $gameData['developer']);
        $this->assertCount(2, $this->sentCompanyQueries());
    }

    public function test_the_lookup_asks_for_company_names_in_the_same_request(): void
    {
        $this->fakeIgdbGames([['id' => 42, 'name' => 'Halo']]);

        $this->assertNotNull((new IGDBService)->searchConsole('Halo', 'X360'));

        $fields = $this->fieldsOf($this->sentGameQueries()[0]);
        $this->assertContains('involved_companies.company.name', $fields);
        $this->assertNotContains('involved_companies.company', $fields);
    }

    public function test_find_game_asks_for_the_game_by_its_id_with_every_relation(): void
    {
        $this->fakeIgdbGames([['id' => 42, 'name' => 'Halo']]);

        $game = (new IGDBService)->findGame(42);

        $this->assertSame(42, $game?->id);
        $queries = $this->sentGameQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('id = 42', $this->whereOf($queries[0]));
        $fields = $this->fieldsOf($queries[0]);
        foreach (['involved_companies.company.name', 'involved_companies.developer', 'game_modes.name', 'player_perspectives.name', 'websites.type'] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    public function test_find_game_returns_null_when_igdb_has_no_such_game(): void
    {
        $this->fakeIgdbGames([]);

        $this->assertNull((new IGDBService)->findGame(42));
    }

    public function test_find_game_throws_when_igdb_fails(): void
    {
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.igdb.test/v4/games' => Http::response('boom', 500),
        ]);

        try {
            (new IGDBService)->findGame(42);
            $this->fail('A failed request must throw.');
        } catch (IgdbHttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
    }

    public function test_find_game_throws_a_429_when_the_rate_limit_is_spent(): void
    {
        $this->fakeIgdbGames([['id' => 42, 'name' => 'Halo']]);
        for ($i = 0; $i < 4; $i++) {
            RateLimiter::hit('igdb_api_rate_limit', 60);
        }

        try {
            (new IGDBService)->findGame(42);
            $this->fail('A spent rate limit must throw.');
        } catch (IgdbHttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertSame([], $this->sentGameQueries());
    }

    public function test_find_game_never_reads_a_cached_answer_but_caches_its_own(): void
    {
        config(['igdb.cache_lifetime' => 86400]);
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.igdb.test/v4/games' => Http::sequence()
                ->push([['id' => 42, 'name' => 'Halo']])
                ->push([['id' => 42, 'name' => 'Halo: Combat Evolved']]),
        ]);
        $service = new IGDBService;

        $this->assertSame('Halo', $service->findGame(42)?->name);
        $this->assertSame('Halo: Combat Evolved', $service->findGame(42)?->name);
        $this->assertCount(2, $this->sentGameQueries());

        $relations = (fn (): array => $this->getGameRelations())->call($service);
        $plain = Game::where('id', 42)->with($relations)->first();

        $this->assertSame('Halo: Combat Evolved', $plain?->name);
        $this->assertCount(2, $this->sentGameQueries());
    }

    public function test_a_plain_query_still_reads_the_cache(): void
    {
        config(['igdb.cache_lifetime' => 86400]);
        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.igdb.test/v4/games' => Http::sequence()
                ->push([['id' => 42, 'name' => 'Halo']])
                ->push([['id' => 42, 'name' => 'Halo: Combat Evolved']]),
        ]);

        $this->assertSame('Halo', Game::where('id', 42)->first()?->name);
        $this->assertSame('Halo', Game::where('id', 42)->first()?->name);
        $this->assertCount(1, $this->sentGameQueries());
    }

    /**
     * @param  list<array<string, mixed>>  $involvedCompanies
     * @return array<string, mixed>
     */
    private function fullDetails(array $involvedCompanies): array
    {
        return [
            'storyline' => '  A long storyline.  ',
            'aggregated_rating' => 91.73,
            'rating' => 78.4,
            'websites' => [
                ['id' => 1, 'type' => 13, 'url' => 'https://store.example.test/'],
                ['id' => 2, 'type' => 1, 'url' => 'https://example.test/a'],
                ['id' => 3, 'type' => 1, 'url' => 'https://example.test/b'],
            ],
            'involved_companies' => $involvedCompanies,
            'game_modes' => [['id' => 1, 'name' => 'Single player'], ['id' => 2, 'name' => 'Multiplayer']],
            'player_perspectives' => [['id' => 4, 'name' => 'Third person']],
        ];
    }

    /**
     * A developer, B publisher, C developer and publisher, D neither, A developer again.
     *
     * @return list<array<string, mixed>>
     */
    private function expandedCompanies(): array
    {
        return [
            ['id' => 1, 'company' => ['id' => 101, 'name' => 'Studio A'], 'developer' => true, 'publisher' => false],
            ['id' => 2, 'company' => ['id' => 102, 'name' => 'Label B'], 'developer' => false, 'publisher' => true],
            ['id' => 3, 'company' => ['id' => 103, 'name' => 'Studio C'], 'developer' => true, 'publisher' => true],
            ['id' => 4, 'company' => ['id' => 104, 'name' => 'Port D'], 'developer' => false, 'publisher' => false],
            ['id' => 5, 'company' => ['id' => 101, 'name' => 'Studio A'], 'developer' => true, 'publisher' => false],
        ];
    }

    private function fakeIgdbCompanies(): void
    {
        $names = [101 => 'Studio A', 102 => 'Label B', 103 => 'Studio C', 104 => 'Port D'];

        Http::fake([
            'https://id.twitch.test/oauth2/token' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'https://api.igdb.test/v4/companies' => function (Request $request) use ($names) {
                preg_match('/where id = (\d+);/', $request->body(), $matches);
                $id = (int) ($matches[1] ?? 0);

                return Http::response(isset($names[$id]) ? [['id' => $id, 'name' => $names[$id]]] : []);
            },
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sentCompanyQueries(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->url() === 'https://api.igdb.test/v4/companies')
            ->map(static fn (array $pair): string => $pair[0]->body())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function xboxAndPcPlatforms(): array
    {
        return [
            ['id' => 9, 'name' => 'PlayStation 3', 'abbreviation' => 'PS3'],
            ['id' => 12, 'name' => 'Xbox 360', 'abbreviation' => 'X360'],
            ['id' => 6, 'name' => 'PC (Microsoft Windows)', 'abbreviation' => 'PC'],
        ];
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
