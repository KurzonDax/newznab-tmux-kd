<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConsoleService;
use App\Services\IGDBService;
use App\Services\ReleaseImageService;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ConsoleServiceDlcParsingTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private DlcTestDouble $service;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        $this->service = new DlcTestDouble;
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_dlc_title_with_hyphen_splits_on_first_hyphen(): void
    {
        $result = $this->service->parseTitle('Guitar.Hero.DLC.Some-Song.X360');

        $this->assertIsArray($result);
        $this->assertSame('1', $result['dlc']);
        $this->assertSame('Guitar Hero DLC Some', $result['title']);
    }

    public function test_dlc_title_with_multiple_hyphens_takes_first_segment(): void
    {
        $result = $this->service->parseTitle('Forza.DLC.Track-Pack-Extra.X360');

        $this->assertIsArray($result);
        $this->assertSame('1', $result['dlc']);
        $this->assertSame('Forza DLC Track', $result['title']);
    }

    public function test_rock_band_network_dlc_sets_title_to_rock_band(): void
    {
        $result = $this->service->parseTitle('Rock.Band.Network.DLC.Song.X360');

        $this->assertIsArray($result);
        $this->assertSame('1', $result['dlc']);
        $this->assertSame('Rock Band', $result['title']);
    }

    public function test_dlc_title_without_hyphen_keeps_dlc_suffix(): void
    {
        $result = $this->service->parseTitle('SomeGame.DLC.X360');

        $this->assertIsArray($result);
        $this->assertSame('1', $result['dlc']);
        $this->assertSame('SomeGame DLC', $result['title']);
    }

    public function test_non_dlc_title_has_no_dlc_flag(): void
    {
        $result = $this->service->parseTitle('Halo.3.X360-PROPER');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('dlc', $result);
        $this->assertSame('Halo 3', $result['title']);
    }

    public function test_title_with_hyphen_is_parsed_correctly(): void
    {
        $result = $this->service->parseTitle('Halo.3.X360-PROPER');

        $this->assertIsArray($result);
        $this->assertSame('Halo 3', $result['title']);
        $this->assertSame('X360', $result['platform']);
    }

    public function test_simple_game_title_extracts_title_and_platform(): void
    {
        $result = $this->service->parseTitle('Gears.of.War.4.X360-REPACK');

        $this->assertIsArray($result);
        $this->assertSame('Gears of War 4', $result['title']);
        $this->assertSame('X360', $result['platform']);
    }

    public function test_title_with_spaces_and_platform(): void
    {
        $result = $this->service->parseTitle('The.Witcher.3.Wild.Hunt.PS4');

        $this->assertIsArray($result);
        $this->assertSame('The Witcher 3 Wild Hunt', $result['title']);
        $this->assertSame('PS4', $result['platform']);
    }

    public function test_xbla_dlc_platform_upgrades_to_xbox360(): void
    {
        $result = $this->service->parseTitle('Some.Game.DLC.XBLA');

        $this->assertIsArray($result);
        $this->assertSame('1', $result['dlc']);
        $this->assertSame('XBOX360', $result['platform']);
    }
}

class DlcTestDouble extends ConsoleService
{
    public function __construct()
    {
        $this->echoOutput = false;
        $this->gameQty = 0;
        $this->lookupThrottleMs = 0;
        $this->imgSavePath = '';
        $this->renamed = false;
        $this->failCache = [];
        $this->igdbService = new class extends IGDBService {};
        $this->imageService = new ReleaseImageService;
    }
}
