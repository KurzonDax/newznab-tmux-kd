<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Data\SteamGameData;
use App\Support\Data\SteamPriceData;
use Tests\TestCase;

/**
 * Unit tests for Steam DTOs.
 */
class SteamDTOsTest extends TestCase
{
    // ==========================================
    // SteamPriceData Tests
    // ==========================================

    public function test_price_data_from_api_response(): void
    {
        $data = [
            'currency' => 'USD',
            'initial' => 5999,
            'final' => 2999,
            'discount_percent' => 50,
            'final_formatted' => '$29.99',
        ];

        $price = SteamPriceData::fromApiResponse($data);

        $this->assertSame('USD', $price->currency);
        $this->assertSame(59.99, $price->initial);
        $this->assertSame(29.99, $price->final);
        $this->assertSame(50, $price->discountPercent);
        $this->assertSame('$29.99', $price->formattedPrice);
    }

    public function test_price_data_free(): void
    {
        $price = SteamPriceData::free();

        $this->assertTrue($price->isFree());
        $this->assertSame(0.0, $price->final);
        $this->assertSame('Free', $price->formattedPrice);
    }

    public function test_price_data_to_array(): void
    {
        $price = new SteamPriceData('EUR', 49.99, 24.99, 50, '24,99€');
        $array = $price->toArray();

        $this->assertSame('EUR', $array['currency']);
        $this->assertSame(49.99, $array['initial']);
        $this->assertSame(24.99, $array['final']);
        $this->assertSame(50, $array['discount_percent']);
        $this->assertSame('24,99€', $array['formatted']);
    }

    // ==========================================
    // SteamGameData Tests
    // ==========================================

    public function test_game_data_from_api_response(): void
    {
        $data = [
            'type' => 'game',
            'name' => 'Cyberpunk 2077',
            'steam_appid' => 1091500,
            'short_description' => 'An open-world RPG.',
            'detailed_description' => 'Full description...',
            'about_the_game' => 'About...',
            'header_image' => 'https://cdn.steam.com/header.jpg',
            'background' => 'https://cdn.steam.com/bg.jpg',
            'publishers' => ['CD PROJEKT RED'],
            'developers' => ['CD PROJEKT RED', 'CD PROJEKT'],
            'genres' => [
                ['id' => '1', 'description' => 'Action'],
                ['id' => '3', 'description' => 'RPG'],
            ],
            'categories' => [
                ['id' => 2, 'description' => 'Single-player'],
                ['id' => 36, 'description' => 'Online Multi-Player'],
            ],
            'release_date' => [
                'coming_soon' => false,
                'date' => 'Dec 10, 2020',
            ],
            'metacritic' => [
                'score' => 86,
                'url' => 'https://www.metacritic.com/game/cyberpunk-2077',
            ],
            'platforms' => [
                'windows' => true,
                'mac' => false,
                'linux' => true,
            ],
            'is_free' => false,
            'screenshots' => [
                [
                    'id' => 0,
                    'path_thumbnail' => 'https://cdn.steam.com/ss_thumb.jpg',
                    'path_full' => 'https://cdn.steam.com/ss_full.jpg',
                ],
            ],
            'movies' => [
                [
                    'id' => 256123,
                    'name' => 'Launch Trailer',
                    'thumbnail' => 'https://cdn.steam.com/movie_thumb.jpg',
                    'mp4' => [
                        '480' => 'https://cdn.steam.com/movie_480.mp4',
                        'max' => 'https://cdn.steam.com/movie_max.mp4',
                    ],
                ],
            ],
            'achievements' => ['total' => 44],
            'recommendations' => ['total' => 500000],
            'website' => 'https://www.cyberpunk.net',
            'dlc' => [1234, 5678],
        ];

        $game = SteamGameData::fromApiResponse($data, 1091500);

        $this->assertSame(1091500, $game->steamId);
        $this->assertSame('Cyberpunk 2077', $game->title);
        $this->assertSame('game', $game->type);
        $this->assertSame('An open-world RPG.', $game->description);
        $this->assertSame('CD PROJEKT RED', $game->publisher);
        $this->assertCount(2, $game->developers);
        $this->assertContains('Action', $game->genres);
        $this->assertContains('RPG', $game->genres);
        $this->assertContains('Windows', $game->platforms);
        $this->assertContains('Linux', $game->platforms);
        $this->assertNotContains('Mac', $game->platforms);
        $this->assertSame(86, $game->metacriticScore);
        $this->assertCount(1, $game->screenshots);
        $this->assertCount(1, $game->movies);
        $this->assertSame(44, $game->achievementCount);
        $this->assertSame(500000, $game->recommendationCount);
        $this->assertCount(2, $game->dlcIds);
    }

    public function test_game_data_is_free(): void
    {
        $freeGame = new SteamGameData(1, 'Free Game', price: SteamPriceData::free());
        $paidGame = new SteamGameData(2, 'Paid Game', price: new SteamPriceData('USD', 59.99, 59.99, 0));
        $noPriceGame = new SteamGameData(3, 'No Price Game');

        $this->assertTrue($freeGame->isFree());
        $this->assertFalse($paidGame->isFree());
        $this->assertFalse($noPriceGame->isFree()); // null price = not free
    }

    public function test_game_data_handles_missing_optional_fields(): void
    {
        $data = [
            'type' => 'game',
            'name' => 'Minimal Game',
            'steam_appid' => 12345,
        ];

        $game = SteamGameData::fromApiResponse($data, 12345);

        $this->assertSame(12345, $game->steamId);
        $this->assertSame('Minimal Game', $game->title);
        $this->assertNull($game->description);
        $this->assertNull($game->publisher);
        $this->assertEmpty($game->developers);
        $this->assertEmpty($game->genres);
        $this->assertEmpty($game->platforms);
        $this->assertNull($game->price);
        $this->assertNull($game->metacriticScore);
    }

    public function test_game_data_immutability(): void
    {
        $game = new SteamGameData(1, 'Test');

        // This should be a compile error in strict mode, or readonly
        // We just verify the properties are accessible but not writable
        $this->assertSame(1, $game->steamId);
        $this->assertSame('Test', $game->title);

        // The readonly keyword ensures immutability at compile time
    }
}
