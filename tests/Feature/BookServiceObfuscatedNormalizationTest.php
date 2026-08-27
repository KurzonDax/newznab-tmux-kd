<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\BookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class BookServiceObfuscatedNormalizationTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['maxbooksprocessed' => '50', 'amazonsleep' => '0', 'lookupbooks' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        DB::table('settings')->upsert([
            ['name' => 'maxbooksprocessed', 'value' => '50'],
            ['name' => 'amazonsleep', 'value' => '0'],
            ['name' => 'lookupbooks', 'value' => '1'],
        ], ['name'], ['value']);

        $this->createSchema();
        Event::fake([ReleaseNameFixed::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_process_book_releases_normalizes_obfuscated_searchnames_for_existing_book_rows(): void
    {
        Search::shouldReceive('updateRelease')->once();

        DB::table('releases')->insert([
            'id' => 1,
            'name' => "N_NZB_[1_6]_-_Woman's_Day_New_Zealand_-_Issue_45_April_27_2026.par2",
            'searchname' => "N_NZB_[1_6]_-_Woman's_Day_New_Zealand_-_Issue_45_April_27_2026.par2",
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::BOOKS_MAGAZINES,
            'videos_id' => 41,
            'tv_episodes_id' => 42,
            'movieinfo_id' => 47,
            'imdbid' => 'tt1234567',
            'musicinfo_id' => 43,
            'consoleinfo_id' => 44,
            'bookinfo_id' => 123,
            'anidbid' => 46,
            'gamesinfo_id' => 48,
            'predb_id' => 0,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'proc_files' => 0,
            'proc_par2' => 0,
            'proc_uid' => 0,
            'proc_hash16k' => 0,
            'proc_srr' => 0,
            'proc_crc32' => 0,
            'passwordstatus' => 0,
            'nzbstatus' => 1,
        ]);

        $service = app(BookService::class);
        $service->processBookReleases();

        $release = Release::query()->findOrFail(1);

        $this->assertSame("Woman's Day New Zealand - Issue 45 April 27 2026", $release->searchname);
        $this->assertSame(123, (int) $release->bookinfo_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(0, (int) $release->is_trusted_name);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(0, (int) $release->tv_episodes_id);
        $this->assertNull($release->movieinfo_id);
        $this->assertNull($release->imdbid);
        $this->assertNull($release->musicinfo_id);
        $this->assertNull($release->consoleinfo_id);
        $this->assertNull($release->anidbid);
        $this->assertSame(0, (int) $release->gamesinfo_id);
        Event::assertDispatched(
            ReleaseNameFixed::class,
            fn (ReleaseNameFixed $event): bool => $event->releaseId === 1
                && $event->newName === "Woman's Day New Zealand - Issue 45 April 27 2026",
        );
    }

    public function test_process_book_releases_normalizes_existing_mcn_magazine_searchname(): void
    {
        Search::shouldReceive('updateRelease')->once();

        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'MCN.April.22.2026.HYBRID.MAGAZINE.eBook-21A1',
            'searchname' => 'MCN.April.22.2026.HYBRID.MAGAZINE.eBook-21A1',
            'groups_id' => 1,
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::BOOKS_MAGAZINES,
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'imdbid' => null,
            'musicinfo_id' => null,
            'consoleinfo_id' => null,
            'bookinfo_id' => -2,
            'anidbid' => null,
            'predb_id' => 0,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'proc_files' => 0,
            'proc_par2' => 0,
            'proc_uid' => 0,
            'proc_hash16k' => 0,
            'proc_srr' => 0,
            'proc_crc32' => 0,
            'passwordstatus' => 0,
            'nzbstatus' => 1,
        ]);

        $service = app(BookService::class);
        $service->processBookReleases();

        $release = Release::query()->findOrFail(2);

        $this->assertSame('MCN - April 22, 2026', $release->searchname);
        $this->assertSame(-2, (int) $release->bookinfo_id);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        if (! Schema::hasTable('releases')) {
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
}
