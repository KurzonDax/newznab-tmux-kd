<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Services\Releases\ReleaseEntityDataLoader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ReleaseEntityDataLoaderTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @param  list<string>  $longTextColumns  The table's long text columns, which labels must not fetch. */
    #[DataProvider('labels')]
    public function test_labels_fetch_only_identity_title_and_year(string $tableName, string $key, string $foreignKey, string $year, int $category, string $root, array $longTextColumns): void
    {
        ProductionTables::fromAuthority()->create($tableName, [$key, 'title', $year, ...$longTextColumns]);
        DB::table($tableName)->insert([
            $key => 7, 'title' => 'A title', $year => '2001-02-03',
            ...array_fill_keys($longTextColumns, str_repeat('t', 1048576)),
        ]);
        if ($root === 'tv') {
            Schema::create('tv_episodes', function (Blueprint $table): void {
                $table->id();
                $table->integer('videos_id');
                $table->integer('series');
                $table->integer('episode');
                $table->text('summary')->nullable();
            });
            DB::table('tv_episodes')->insert(['id' => 3, 'videos_id' => 7, 'series' => 2, 'episode' => 4, 'summary' => 'Not a label']);
        }
        $queries = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening) {
                $queries[] = $query;
            }
        });

        $entities = (new ReleaseEntityDataLoader)->load(collect([(object) [
            'id' => 1, 'categories_id' => $category, $foreignKey => 7, 'tv_episodes_id' => 3,
        ]]));
        $listening = false;

        self::assertSame('A title', $entities[1]->title);
        self::assertSame('2001', $entities[1]->year);
        self::assertSame('7', $entities[1]->id);
        self::assertSame($root, $entities[1]->root);
        self::assertSame($root === 'tv' ? 2 : null, $entities[1]->season);
        self::assertSame($root === 'tv' ? 4 : null, $entities[1]->episode);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('*', $query->sql);
            self::assertStringNotContainsString('plot', $query->sql);
            self::assertStringNotContainsString('review', $query->sql);
            $records = DB::select($query->sql, $query->bindings);
            self::assertCount(1, $records);
            self::assertSame(
                str_contains($query->sql, 'tv_episodes') ? ['id', 'series', 'episode', 'videos_id'] : [$key, 'title', $year],
                array_keys((array) $records[0]),
            );
        }
    }

    public function test_anime_returns_only_the_preferred_title_for_each_identity(): void
    {
        ProductionTables::fromAuthority()->create('anidb_info', ['anidbid', 'startdate']);
        Schema::create('anidb_titles', function (Blueprint $table): void {
            $table->integer('anidbid');
            $table->string('lang');
            $table->string('type');
            $table->string('title');
            $table->primary(['anidbid', 'type', 'lang', 'title']);
        });
        DB::table('anidb_info')->insert([
            ['anidbid' => 7, 'startdate' => '2002-03-04'],
            ['anidbid' => 8, 'startdate' => null],
            ['anidbid' => 9, 'startdate' => null],
        ]);
        DB::table('anidb_titles')->insert([
            ['anidbid' => 7, 'lang' => 'en', 'type' => 'main', 'title' => 'Chosen'],
            ['anidbid' => 7, 'lang' => 'en', 'type' => 'main', 'title' => 'Zebra'],
            ['anidbid' => 7, 'lang' => 'en', 'type' => 'official', 'title' => 'A official'],
            ['anidbid' => 7, 'lang' => 'x-jat', 'type' => 'main', 'title' => 'A romanized'],
            ['anidbid' => 7, 'lang' => 'ja', 'type' => 'main', 'title' => 'A native'],
            ['anidbid' => 8, 'lang' => 'ja', 'type' => 'main', 'title' => 'Native'],
            ['anidbid' => 8, 'lang' => 'x-jat', 'type' => 'official', 'title' => 'Romanized'],
        ]);
        $queries = [];
        $listening = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening) {
                $queries[] = $query;
            }
        });

        $entities = (new ReleaseEntityDataLoader)->load(collect([
            (object) ['id' => 1, 'categories_id' => 5070, 'anidbid' => 7],
            (object) ['id' => 2, 'categories_id' => 5070, 'anidbid' => 8],
            (object) ['id' => 3, 'categories_id' => 5070, 'anidbid' => 9],
        ]));
        $listening = false;

        self::assertSame('Chosen', $entities[1]->title);
        self::assertSame('2002', $entities[1]->year);
        self::assertSame('Romanized', $entities[2]->title);
        self::assertNull($entities[2]->year);
        self::assertArrayNotHasKey(3, $entities);
        self::assertCount(1, $queries);
        self::assertCount(2, DB::select($queries[0]->sql, $queries[0]->bindings));
        self::assertStringContainsString('titles.title, titles.type, titles.lang', $queries[0]->sql);
    }

    public static function labels(): array
    {
        return [
            'movie' => ['movieinfo', 'imdbid', 'imdbid', 'year', 2030, 'movies', ['plot']],
            'tv' => ['videos', 'id', 'videos_id', 'started', 5030, 'tv', []],
            'music' => ['musicinfo', 'id', 'musicinfo_id', 'year', 3030, 'audio', ['review']],
            'console' => ['consoleinfo', 'id', 'consoleinfo_id', 'releasedate', 1030, 'console', ['review']],
            'games' => ['gamesinfo', 'id', 'gamesinfo_id', 'releasedate', 4030, 'games', ['review']],
            'book' => ['bookinfo', 'id', 'bookinfo_id', 'publishdate', 7030, 'books', ['overview']],
        ];
    }
}
