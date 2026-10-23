<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PopulateAniListService;
use App\Services\ProviderGateway\ProviderGateway;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AniListServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.echocli' => false,
        ]);
        Carbon::setTestNow('2026-10-23 12:00:00');
        Sleep::fake(syncWithCarbon: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_search_anime_returns_media_results(): void
    {
        $service = $this->makeServiceWithResponses(
            $this->graphqlResponse([
                'data' => [
                    'Page' => [
                        'media' => [[
                            'id' => 9253,
                            'idMal' => 9253,
                            'title' => [
                                'romaji' => 'Steins;Gate',
                                'english' => 'Steins;Gate',
                                'native' => 'Steins;Gate',
                            ],
                            'episodes' => 24,
                            'averageScore' => 91,
                            'format' => 'TV',
                            'genres' => ['Sci-Fi', 'Thriller'],
                        ]],
                    ],
                ],
            ])
        );

        $result = $service->searchAnime('Steins;Gate', 1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame(9253, $result[0]['id']);
        $this->assertSame(9253, $result[0]['idMal']);
        $this->assertSame('Steins;Gate', $result[0]['title']['romaji']);
    }

    public function test_search_anime_returns_empty_array_when_no_matches_exist(): void
    {
        $service = $this->makeServiceWithResponses(
            $this->graphqlResponse([
                'data' => [
                    'Page' => [
                        'media' => [],
                    ],
                ],
            ])
        );

        $result = $service->searchAnime('NonExistentAnime12345');

        $this->assertSame([], $result);
    }

    public function test_get_anime_by_id_returns_media_payload(): void
    {
        $service = $this->makeServiceWithResponses(
            $this->graphqlResponse([
                'data' => [
                    'Media' => [
                        'id' => 5114,
                        'idMal' => 5114,
                        'title' => [
                            'romaji' => 'Fullmetal Alchemist: Brotherhood',
                            'english' => 'Fullmetal Alchemist: Brotherhood',
                            'native' => '鋼の錬金術師 FULLMETAL ALCHEMIST',
                        ],
                        'format' => 'TV',
                    ],
                ],
            ])
        );

        $result = $service->getAnimeById(5114);

        $this->assertIsArray($result);
        $this->assertSame(5114, $result['id']);
        $this->assertSame('TV', $result['format']);
    }

    public function test_get_anime_by_id_returns_false_when_api_returns_errors(): void
    {
        $service = $this->makeServiceWithResponses(
            $this->graphqlResponse([
                'errors' => [
                    ['message' => 'Bad query'],
                ],
            ])
        );

        $result = $service->getAnimeById(999999);

        $this->assertFalse($result);
    }

    public function test_a_429_without_a_short_retry_after_pauses_anilist_for_fifteen_minutes(): void
    {
        $transport = new MockHandler([
            new Response(429),
            $this->graphqlResponse(['data' => ['Page' => ['media' => []]]]),
        ]);
        $service = $this->makeServiceWithHandler($transport);

        try {
            $service->searchAnime('Steins;Gate');
            $this->fail('A 429 must pause AniList.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Paused until', $exception->getMessage());
        }

        $this->assertEquals(now()->addMinutes(15)->getTimestamp(), app(ProviderGateway::class)->heldUntil('anilist')?->getTimestamp());

        try {
            $service->searchAnime('Steins;Gate');
            $this->fail('A paused AniList must not be asked.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Paused until', $exception->getMessage());
        }
        $this->assertCount(1, $transport, 'nothing was sent during the pause');
    }

    public function test_a_429_with_a_short_retry_after_is_waited_and_retried(): void
    {
        $service = $this->makeServiceWithResponses(
            new Response(429, ['Retry-After' => '5']),
            $this->graphqlResponse(['data' => ['Page' => ['media' => []]]]),
        );

        $started = now()->copy();

        $this->assertSame([], $service->searchAnime('Steins;Gate'));
        $this->assertGreaterThanOrEqual(5, $started->diffInSeconds(now()), 'the retry waited out the Retry-After');
        $this->assertNull(app(ProviderGateway::class)->heldUntil('anilist'), 'no fifteen-minute pause');
    }

    private function makeServiceWithResponses(Response ...$responses): PopulateAniListService
    {
        return $this->makeServiceWithHandler(new MockHandler($responses));
    }

    private function makeServiceWithHandler(MockHandler $transport): PopulateAniListService
    {
        app(ProviderGateway::class)->useHandler('anilist', $transport);

        return new PopulateAniListService;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function graphqlResponse(array $body, int $status = 200): Response
    {
        return new Response(
            $status,
            [
                'X-RateLimit-Remaining' => '19',
                'X-RateLimit-Reset' => (string) (time() + 60),
            ],
            json_encode($body) ?: '{}'
        );
    }
}
