<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GoogleBooksService;
use App\Services\IGDBService;
use App\Services\IsbnDbService;
use App\Services\ItunesService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\OpenLibraryService;
use App\Services\ReleaseImageService;
use App\Support\BookMatchScorer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * `amazonsleep` paces the external metadata lookups the book and console passes make.
 *
 * The throttle only owes time to a release that actually called out, and it tops the window
 * up from a monotonic measurement, so a sub-second window paces every release instead of
 * alternating between a full wait and none as the wall-clock second ticks over.
 */
class MetadataLookupThrottleTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'lookupbooks' => '1',
            'lookupgames' => '1',
            'maxbooksprocessed' => '50',
            'maxgamesprocessed' => '50',
            'amazonsleep' => '0',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_book_pass_does_not_wait_for_a_release_it_never_looked_up(): void
    {
        $this->storeSetting('amazonsleep', '3000');
        $this->insertRelease(1, 'Placeholder', Category::BOOKS_EBOOK);

        $service = new BookService;
        $elapsed = $this->timeOf(static fn () => $service->processBookReleases());

        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame(-2, (int) DB::table('releases')->where('id', 1)->value('bookinfo_id'));
    }

    public function test_the_console_pass_does_not_wait_for_a_release_it_never_looked_up(): void
    {
        $this->storeSetting('amazonsleep', '3000');
        $this->insertRelease(1, 'Some Release Naming No Platform At All', Category::GAME_XBOX360);

        $igdb = Mockery::mock(IGDBService::class);
        $igdb->shouldNotReceive('searchConsole');

        $service = new ConsoleService(Mockery::mock(ReleaseImageService::class), $igdb);
        $elapsed = $this->timeOf(static fn () => $service->processConsoleReleases());

        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame(-2, (int) DB::table('releases')->where('id', 1)->value('consoleinfo_id'));
    }

    public function test_the_console_pass_paces_each_external_lookup_within_a_sub_second_window(): void
    {
        $this->storeSetting('amazonsleep', '250');
        $this->insertRelease(1, 'Halo 3 PAL XBOX360 -GAMERS', Category::GAME_XBOX360);
        $this->insertRelease(2, 'Gears Of War PAL XBOX360 -GAMERS', Category::GAME_XBOX360);

        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')->andReturn(['id' => []]);

        $igdb = Mockery::mock(IGDBService::class);
        $igdb->shouldReceive('isConfigured')->twice()->andReturnTrue();
        $igdb->shouldReceive('searchConsole')->twice()->andReturnUsing(static function (): null {
            usleep(150_000); // A lookup that spends most of the window talking to IGDB.

            return null;
        });

        $service = new ConsoleService(Mockery::mock(ReleaseImageService::class), $igdb);
        $elapsed = $this->timeOf(static fn () => $service->processConsoleReleases());

        // Two 250ms windows, each already 150ms spent on the lookup itself. Measuring the
        // lookup in whole seconds would add a full window on top of every one of them.
        $this->assertGreaterThanOrEqual(0.49, $elapsed);
        $this->assertLessThan(0.75, $elapsed);
    }

    public function test_the_book_pass_paces_each_external_lookup_within_a_sub_second_window(): void
    {
        $this->storeSetting('amazonsleep', '250');
        $this->insertRelease(1, 'Clean Code Robert Martin EPUB', Category::BOOKS_EBOOK);
        $this->insertRelease(2, 'Atomic Habits James Clear EPUB', Category::BOOKS_EBOOK);

        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchSecondary')->andReturn(['id' => []]);

        $isbnDb = Mockery::mock(IsbnDbService::class);
        $isbnDb->shouldReceive('isConfigured')->andReturnTrue();
        $isbnDb->shouldReceive('searchBooks')->twice()->andReturnUsing(static function (): array {
            usleep(150_000); // A lookup that spends most of the window talking to ISBNdb.

            return [];
        });

        $service = new BookService(
            $isbnDb,
            $this->emptyProvider(GoogleBooksService::class, 'searchBooks'),
            $this->emptyProvider(OpenLibraryService::class, 'searchBooks'),
            $this->itunesFindingNothing(),
            new BookMatchScorer,
            Mockery::mock(ReleaseImageService::class),
            $this->releaseUpdateServiceIgnoringRenames(),
        );

        $elapsed = $this->timeOf(static fn () => $service->processBookReleases());

        $this->assertGreaterThanOrEqual(0.49, $elapsed);
        $this->assertLessThan(0.75, $elapsed);
    }

    /**
     * @param  class-string  $provider
     */
    private function emptyProvider(string $provider, string $method): object
    {
        $mock = Mockery::mock($provider);
        $mock->shouldReceive($method)->andReturn([]);

        return $mock;
    }

    private function itunesFindingNothing(): ItunesService
    {
        $itunes = Mockery::mock(ItunesService::class);
        $itunes->shouldReceive('findEbooks')->andReturn([]);
        $itunes->shouldReceive('lastRequestFailed')->andReturnFalse();

        return $itunes;
    }

    private function releaseUpdateServiceIgnoringRenames(): ReleaseUpdateService
    {
        $releaseUpdates = Mockery::mock(ReleaseUpdateService::class);
        $releaseUpdates->shouldReceive('renameFromBookMetadata')->andReturnNull();

        return $releaseUpdates;
    }

    /**
     * @param  callable(): void  $work
     */
    private function timeOf(callable $work): float
    {
        $startedAt = hrtime(true);
        $work();

        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }

    private function storeSetting(string $name, string $value): void
    {
        DB::table('settings')->upsert([['name' => $name, 'value' => $value]], ['name'], ['value']);
    }

    private function insertRelease(int $id, string $searchName, int $categoryId): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => $searchName,
            'searchname' => $searchName,
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat((string) $id, 40),
            'leftguid' => (string) $id,
            'fromname' => 'poster@example.com',
            'categories_id' => $categoryId,
            'isrenamed' => 1,
            'nzbstatus' => 1,
        ]);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('searchname_normalized')->nullable();
            $table->string('display_name')->nullable();
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->string('fromname')->nullable();
            $table->integer('categories_id')->default(Category::OTHER_MISC);
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->integer('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->tinyInteger('iscategorized')->default(0);
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('is_trusted_name')->default(0);
            $table->tinyInteger('proc_nfo')->default(0);
            $table->tinyInteger('proc_files')->default(0);
            $table->tinyInteger('proc_par2')->default(0);
            $table->tinyInteger('proc_uid')->default(0);
            $table->tinyInteger('proc_hash16k')->default(0);
            $table->tinyInteger('proc_srr')->default(0);
            $table->tinyInteger('proc_crc32')->default(0);
            $table->tinyInteger('passwordstatus')->default(0);
            $table->tinyInteger('nzbstatus')->default(0);
        });
    }
}
