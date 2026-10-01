<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ImageAssetProfile;
use App\Services\ConsoleService;
use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use App\Services\MetadataProcessing\ConsoleGenres;
use App\Services\ReleaseImageService;
use App\Support\Data\ImageProcessingResult;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class ConsoleServiceIgdbDelegationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_fetch_igdb_properties_delegates_to_igdb_service_and_keeps_the_genre_names(): void
    {
        $igdbService = Mockery::mock(IGDBService::class);
        $game = new Game(['id' => 42, 'name' => 'Halo']);

        $igdbService->shouldReceive('isConfigured')->once()->andReturn(true);
        $igdbService->shouldReceive('searchConsole')->once()->with('Halo', 'Xbox 360')->andReturn($game);
        $igdbService->shouldReceive('buildConsoleData')->once()->with($game, 'Xbox 360')->andReturn([
            'title' => 'Halo',
            'asin' => '42',
            'review' => 'Sci-fi shooter',
            'coverurl' => 'https://images.example.test/halo.jpg',
            'releasedate' => '2001-11-15',
            'esrb' => 'M',
            'url' => 'https://www.igdb.com/games/halo',
            'publisher' => 'Microsoft',
            'platform' => 'Xbox 360',
            'consolegenre' => 'Shooter,Adventure',
            'consolegenres' => ['Shooter', 'Adventure'],
            'salesrank' => '',
        ]);

        /** @var ConsoleServiceTestDouble $service */
        $service = (new ReflectionClass(ConsoleServiceTestDouble::class))->newInstanceWithoutConstructor();

        $service->initialize($igdbService);

        $result = $service->fetchIGDBProperties('Halo', 'X360');

        $this->assertIsArray($result);
        $this->assertSame('Xbox 360', $result['platform']);
        $this->assertSame('Shooter,Adventure', $result['consolegenre']);
        $this->assertSame(['Shooter', 'Adventure'], $result['consolegenres']);
        $this->assertArrayNotHasKey('consolegenreid', $result);
    }

    public function test_a_new_game_without_an_age_rating_is_stored_with_a_null_esrb(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFindingAnUnratedGame()->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertGreaterThan(0, $consoleId);
        $this->assertSame(1, DB::table('consoleinfo')->count());
        $this->assertNull(DB::table('consoleinfo')->where('id', $consoleId)->value('esrb'));
    }

    public function test_an_existing_game_without_an_age_rating_is_updated_to_a_null_esrb(): void
    {
        $this->createConsoleTables();
        $existingId = (int) DB::table('consoleinfo')->insertGetId([
            'title' => 'Halo',
            'asin' => '42',
            'platform' => 'Xbox 360',
            'esrb' => '94%',
            'cover' => 0,
        ]);

        $consoleId = $this->serviceFindingAnUnratedGame()->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame($existingId, (int) $consoleId);
        $this->assertSame(1, DB::table('consoleinfo')->count());
        $this->assertNull(DB::table('consoleinfo')->where('id', $existingId)->value('esrb'));
    }

    public function test_a_game_without_any_release_date_is_stored_with_a_null_release_date(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFindingAnUnratedGame()->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertGreaterThan(0, $consoleId);
        $this->assertNull(DB::table('consoleinfo')->where('id', $consoleId)->value('releasedate'));
    }

    public function test_an_existing_game_without_any_release_date_is_updated_to_a_null_release_date(): void
    {
        $this->createConsoleTables();
        $existingId = (int) DB::table('consoleinfo')->insertGetId([
            'title' => 'Halo',
            'asin' => '42',
            'platform' => 'Xbox 360',
            'releasedate' => '2026-09-30 00:00:00',
            'cover' => 0,
        ]);

        $consoleId = $this->serviceFindingAnUnratedGame()->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame($existingId, (int) $consoleId);
        $this->assertNull(DB::table('consoleinfo')->where('id', $existingId)->value('releasedate'));
    }

    public function test_a_new_game_whose_cover_is_saved_is_stored_with_cover_set(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFindingAGameWithACover(
            ImageProcessingResult::success('/covers/console/1.jpg', 264, 374, 'image/jpeg'),
            '1',
        )->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame(1, (int) $consoleId);
        $this->assertSame(1, (int) DB::table('consoleinfo')->where('id', $consoleId)->value('cover'));
    }

    public function test_a_new_game_whose_cover_fails_to_save_is_stored_with_cover_unset(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFindingAGameWithACover(
            ImageProcessingResult::failure('Remote image could not be fetched.'),
            '1',
        )->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame(1, (int) $consoleId);
        $this->assertSame(0, (int) DB::table('consoleinfo')->where('id', $consoleId)->value('cover'));
    }

    public function test_an_existing_game_whose_cover_is_saved_is_updated_with_cover_set(): void
    {
        $this->createConsoleTables();
        $existingId = (int) DB::table('consoleinfo')->insertGetId([
            'title' => 'Halo',
            'asin' => '42',
            'platform' => 'Xbox 360',
            'cover' => 0,
        ]);

        $consoleId = $this->serviceFindingAGameWithACover(
            ImageProcessingResult::success('/covers/console/1.jpg', 264, 374, 'image/jpeg'),
            (string) $existingId,
        )->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame($existingId, (int) $consoleId);
        $this->assertSame(1, (int) DB::table('consoleinfo')->where('id', $existingId)->value('cover'));
    }

    public function test_a_game_with_two_genres_is_stored_with_one_genre_row_each_in_igdb_order(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFinding(['id' => 42, 'name' => 'Halo', 'genres' => [['name' => 'Shooter'], ['name' => 'Adventure']]])
            ->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame(['Adventure', 'Shooter'], DB::table('genres')->where('type', 1000)->orderBy('title')->pluck('title')->all());
        $this->assertSame(0, DB::table('genres')->where('title', 'Shooter,Adventure')->count());
        $this->assertSame([['Shooter', 0], ['Adventure', 1]], $this->storedGenres((int) $consoleId));
        $this->assertSame($this->genreId('Shooter'), (int) DB::table('consoleinfo')->where('id', $consoleId)->value('genres_id'));
    }

    public function test_a_second_game_reuses_an_existing_genre_row(): void
    {
        $this->createConsoleTables();
        $this->serviceFinding(['id' => 42, 'name' => 'Halo', 'genres' => [['name' => 'Shooter'], ['name' => 'Adventure']]])
            ->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $consoleId = $this->serviceFinding(['id' => 43, 'name' => 'Zelda', 'genres' => [['name' => 'Adventure']]])
            ->updateConsoleInfo(['title' => 'Zelda', 'platform' => 'X360']);

        $this->assertSame(1, DB::table('genres')->where('type', 1000)->where('title', 'Adventure')->count());
        $this->assertSame([['Adventure', 0]], $this->storedGenres((int) $consoleId));
    }

    public function test_a_game_with_no_genres_and_no_themes_links_to_the_unknown_genre(): void
    {
        $this->createConsoleTables();
        $unknownId = (int) DB::table('genres')->insertGetId(['title' => 'Unknown', 'type' => 1000, 'disabled' => 0]);

        $consoleId = $this->serviceFinding(['id' => 42, 'name' => 'Halo'])->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame(1, DB::table('genres')->where('title', 'Unknown')->count());
        $this->assertSame([['Unknown', 0]], $this->storedGenres((int) $consoleId));
        $this->assertSame($unknownId, (int) DB::table('consoleinfo')->where('id', $consoleId)->value('genres_id'));
    }

    public function test_a_game_with_no_genres_and_no_themes_creates_the_unknown_genre_when_none_exists(): void
    {
        $this->createConsoleTables();

        $consoleId = $this->serviceFinding(['id' => 42, 'name' => 'Halo'])->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame([$this->genreId('Unknown')], DB::table('genres')->where('type', 1000)->pluck('id')->map(intval(...))->all());
        $this->assertSame([['Unknown', 0]], $this->storedGenres((int) $consoleId));
        $this->assertSame($this->genreId('Unknown'), (int) DB::table('consoleinfo')->where('id', $consoleId)->value('genres_id'));
    }

    public function test_a_relookup_whose_genres_changed_replaces_the_genre_rows(): void
    {
        $this->createConsoleTables();
        $consoleId = $this->serviceFinding(['id' => 42, 'name' => 'Halo', 'genres' => [['name' => 'Shooter'], ['name' => 'Adventure']]])
            ->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $relookupId = $this->serviceFinding(['id' => 42, 'name' => 'Halo', 'genres' => [['name' => 'Puzzle']]])
            ->updateConsoleInfo(['title' => 'Halo', 'platform' => 'X360']);

        $this->assertSame((int) $consoleId, (int) $relookupId);
        $this->assertSame([['Puzzle', 0]], $this->storedGenres((int) $consoleId));
        $this->assertSame($this->genreId('Puzzle'), (int) DB::table('consoleinfo')->where('id', $consoleId)->value('genres_id'));
    }

    public function test_parse_title_no_longer_returns_legacy_browse_node(): void
    {
        /** @var ConsoleServiceTestDouble $service */
        $service = (new ReflectionClass(ConsoleServiceTestDouble::class))->newInstanceWithoutConstructor();

        $result = $service->parseTitle('Halo.3.X360-PROPER');

        $this->assertIsArray($result);
        $this->assertSame('Halo 3', $result['title']);
        $this->assertSame('X360', $result['platform']);
        $this->assertArrayNotHasKey('node', $result);
    }

    private function createConsoleTables(): void
    {
        foreach (['consoleinfo', 'genres', 'console_genres'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
    }

    /** @return list<array{string, int}> */
    private function storedGenres(int $consoleId): array
    {
        return DB::table('console_genres as cg')->join('genres as g', 'g.id', '=', 'cg.genres_id')
            ->where('cg.consoleinfo_id', $consoleId)->orderBy('cg.position')->get(['g.title', 'cg.position'])
            ->map(static fn (object $row): array => [(string) $row->title, (int) $row->position])->all();
    }

    private function genreId(string $title): int
    {
        return (int) DB::table('genres')->where('type', 1000)->where('title', $title)->value('id');
    }

    /** @param array<string, mixed> $attributes */
    private function serviceFinding(array $attributes): ConsoleServiceTestDouble
    {
        $igdbService = Mockery::mock(IGDBService::class)->makePartial();
        $igdbService->shouldReceive('isConfigured')->andReturn(true);
        $igdbService->shouldReceive('searchConsole')->once()->andReturn(new Game($attributes));

        /** @var ConsoleServiceTestDouble $service */
        $service = (new ReflectionClass(ConsoleServiceTestDouble::class))->newInstanceWithoutConstructor();
        $service->initialize($igdbService);

        return $service;
    }

    private function serviceFindingAnUnratedGame(): ConsoleServiceTestDouble
    {
        $game = new Game([
            'id' => 42,
            'name' => 'Halo',
            'aggregated_rating' => 94.2,
            'genres' => [['name' => 'Action']],
        ]);

        $igdbService = Mockery::mock(IGDBService::class)->makePartial();
        $igdbService->shouldReceive('isConfigured')->andReturn(true);
        $igdbService->shouldReceive('searchConsole')->once()->with('Halo', 'Xbox 360')->andReturn($game);

        /** @var ConsoleServiceTestDouble $service */
        $service = (new ReflectionClass(ConsoleServiceTestDouble::class))->newInstanceWithoutConstructor();
        $service->initialize($igdbService);

        return $service;
    }

    private function serviceFindingAGameWithACover(ImageProcessingResult $saveResult, string $expectedImageName): ConsoleServiceTestDouble
    {
        $game = new Game([
            'id' => 42,
            'name' => 'Halo',
            'cover' => ['image_id' => 'co1abc'],
            'genres' => [['name' => 'Action']],
        ]);

        $igdbService = Mockery::mock(IGDBService::class)->makePartial();
        $igdbService->shouldReceive('isConfigured')->andReturn(true);
        $igdbService->shouldReceive('searchConsole')->once()->with('Halo', 'Xbox 360')->andReturn($game);

        $imageService = Mockery::mock(ReleaseImageService::class);
        $imageService->shouldReceive('saveRemoteImage')
            ->once()
            ->withArgs(fn (string $imgName, string $url, string $directory, ImageAssetProfile $profile): bool => $imgName === $expectedImageName
                && $url === 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1abc.jpg'
                && $profile === ImageAssetProfile::MetadataCover)
            ->andReturn($saveResult);

        /** @var ConsoleServiceTestDouble $service */
        $service = (new ReflectionClass(ConsoleServiceTestDouble::class))->newInstanceWithoutConstructor();
        $service->initialize($igdbService, $imageService);

        return $service;
    }
}

class ConsoleServiceTestDouble extends ConsoleService
{
    public function initialize(IGDBService $igdbService, ?ReleaseImageService $imageService = null): void
    {
        $this->echoOutput = false;
        $this->gameQty = 0;
        $this->lookupThrottleMs = 0;
        $this->imgSavePath = '';
        $this->renamed = false;
        $this->failCache = [];
        $this->igdbService = $igdbService;
        $this->imageService = $imageService ?? new ReleaseImageService;
        $this->consoleGenres = new ConsoleGenres;
    }
}
