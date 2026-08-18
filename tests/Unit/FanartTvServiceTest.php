<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FanartTvService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FanartTvServiceTest extends TestCase
{
    #[Test]
    public function it_extracts_the_highest_liked_tv_artwork_from_a_faked_response(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'webservice.fanart.tv/v3/tv/81189*' => Http::response([
                'name' => 'Breaking Bad',
                'tvposter' => [
                    ['url' => 'https://assets.example/low-poster.jpg', 'likes' => '2'],
                    ['url' => 'https://assets.example/best-poster.jpg', 'likes' => '14'],
                ],
                'tvbanner' => [
                    ['url' => 'https://assets.example/low-banner.jpg', 'likes' => '1'],
                    ['url' => 'https://assets.example/best-banner.jpg', 'likes' => '9'],
                ],
                'showbackground' => [
                    ['url' => 'https://assets.example/background.jpg', 'likes' => '4'],
                ],
                'hdtvlogo' => [
                    ['url' => 'https://assets.example/logo.png', 'likes' => '3'],
                ],
            ]),
        ]);

        $properties = (new FanartTvService('test-api-key'))->getTvProperties(81189);

        $this->assertSame([
            'poster' => 'https://assets.example/best-poster.jpg',
            'banner' => 'https://assets.example/best-banner.jpg',
            'background' => 'https://assets.example/background.jpg',
            'logo' => 'https://assets.example/logo.png',
            'title' => 'Breaking Bad',
        ], $properties);
        Http::assertSentCount(1);
    }
}
