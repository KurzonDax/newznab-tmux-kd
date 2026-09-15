<?php

declare(strict_types=1);

namespace Tests\Feature\Releases;

use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseRowDataLoader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseRowDataLoaderTest extends TestCase
{
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createReleaseSchema();
        Schema::table('releases', function (Blueprint $table): void {
            $table->integer('passwordstatus')->nullable()->change();
            $table->integer('nfostatus')->nullable()->change();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[DataProvider('rowTypes')]
    public function test_complete_rows_are_used_without_refetching_even_with_loaded_nulls(bool $eloquent): void
    {
        $rows = collect(range(1, 100))->map(function (int $id) use ($eloquent): object {
            $attributes = $this->attributes($id);

            return $eloquent ? (new Release)->setRawAttributes($attributes) : (object) $attributes;
        });
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "releases"')) {
                $queries[] = $query->sql;
            }
        });

        (new ReleaseRowDataLoader)->load($rows);

        self::assertSame([], $queries);
        foreach ($rows as $index => $row) {
            self::assertSame($this->expectedRowJson($index + 1), json_encode($row->row_data, JSON_THROW_ON_ERROR));
        }
    }

    public function test_partial_rows_fetch_one_projection_and_keep_order_identity_and_computed_fields(): void
    {
        DB::table('releases')->insert(array_map($this->attributes(...), range(1, 100)));
        $rows = collect(range(100, 1))->map(static fn (int $id): object => (object) [
            'id' => $id, 'total_report_count' => 7, 'report_response_count' => 2,
            'has_media_info' => true, 'media_info_summary' => '1080p · AVC', 'has_audio_preview' => true,
        ]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "releases"')) {
                $queries[] = $query;
            }
        });

        $loaded = (new ReleaseRowDataLoader)->load($rows);

        self::assertCount(1, $queries);
        self::assertCount(100, $queries[0]->bindings);
        self::assertStringNotContainsString('*', $queries[0]->sql);
        self::assertStringNotContainsString('"name"', $queries[0]->sql);
        self::assertSame($rows->all(), $loaded->all());
        self::assertSame('Display title', $rows[0]->display_name);
        self::assertSame(7, $rows[0]->total_report_count);
        self::assertSame(
            '{"id":100,"guid":"release-100","name":"Display title","category":"","size":"500.00 MB","files":3,"added":"","posted":"","grabs":4,"comments":2,"completion":99.5,"repair_outcome":null,"rescan_outcome":null,"passworded":false,"has_media_info":true,"media_info_summary":"1080p · AVC","nfo":false,"preview":"audio","group":"","poster":"Poster","renamed":true,"pp_done":false,"entity":null,"in_basket":false,"watched":false,"reports":7,"public_responses":2}',
            json_encode($rows[0]->row_data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function test_mixed_rows_preserve_explicit_nulls_and_omit_deleted_partial_rows(): void
    {
        DB::table('releases')->insert($this->attributes(2));
        $complete = (object) $this->attributes(1);
        $partial = (new Release)->setRawAttributes(['id' => 2, 'display_name' => null, 'searchname' => 'Caller.Name']);
        $deleted = (object) ['id' => 3];
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "releases"')) {
                $queries[] = $query->bindings;
            }
        });

        $loaded = (new ReleaseRowDataLoader)->load([$complete, $deleted, $partial]);

        self::assertSame([[3, 2]], $queries);
        self::assertSame([$complete, $partial], $loaded->all());
        self::assertNull($partial->display_name);
        self::assertSame($this->expectedRowJson(1), json_encode($complete->row_data, JSON_THROW_ON_ERROR));
        self::assertSame($this->expectedRowJson(2, 'Caller.Name'), json_encode($partial->row_data, JSON_THROW_ON_ERROR));
        self::assertFalse(property_exists($deleted, 'row_data'));
    }

    public function test_fallback_queries_are_bounded_to_500_distinct_ids(): void
    {
        $rows = array_map(static fn (int $id): object => (object) ['id' => $id], range(1, 501));
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "releases"')) {
                $queries[] = count($query->bindings);
            }
        });

        self::assertCount(0, (new ReleaseRowDataLoader)->load($rows));
        self::assertSame([500, 1], $queries);
    }

    #[DataProvider('containers')]
    public function test_browse_hydration_omits_deleted_rows_without_changing_paginator_total(string $container): void
    {
        foreach (['2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        DB::table('releases')->insert($this->attributes(2));
        $partial = (object) ['id' => 2];
        $input = [(object) ['id' => 99], $partial];
        $page = match ($container) {
            'array' => $input,
            'collection' => collect($input),
            default => new LengthAwarePaginator($input, 200, 100),
        };

        $loaded = app(ReleaseBrowseService::class)->loadReleaseRows($page);

        self::assertSame([$partial], $loaded);
        if ($page instanceof LengthAwarePaginator) {
            self::assertSame(200, $page->total());
            self::assertSame([$partial], $page->items());
        } elseif ($page instanceof Collection) {
            self::assertSame([$partial], $page->all());
        }
    }

    public static function containers(): array
    {
        return ['array' => ['array'], 'collection' => ['collection'], 'paginator' => ['paginator']];
    }

    public static function rowTypes(): array
    {
        return ['objects' => [false], 'models' => [true]];
    }

    private function expectedRowJson(int $id, string $name = 'Display title'): string
    {
        return json_encode([
            'id' => $id, 'guid' => 'release-'.$id, 'name' => $name, 'category' => '', 'size' => '500.00 MB',
            'files' => 3, 'added' => '', 'posted' => '', 'grabs' => 4, 'comments' => 2, 'completion' => 99.5,
            'repair_outcome' => null, 'rescan_outcome' => null, 'passworded' => false,
            'has_media_info' => false, 'media_info_summary' => null, 'nfo' => false, 'preview' => 'none',
            'group' => '', 'poster' => 'Poster', 'renamed' => true, 'pp_done' => false, 'entity' => null,
            'in_basket' => false, 'watched' => false, 'reports' => 0, 'public_responses' => 0,
        ], JSON_THROW_ON_ERROR);
    }

    private function attributes(int $id): array
    {
        return [
            'id' => $id, 'guid' => 'release-'.$id, 'searchname' => 'Original.Title', 'display_name' => 'Display title',
            'categories_id' => null, 'size' => 524288000, 'totalpart' => 3, 'adddate' => null, 'postdate' => null,
            'grabs' => 4, 'comments' => 2, 'completion' => 99.5, 'repair_outcome' => null, 'rescan_outcome' => null,
            'passwordstatus' => null, 'nfostatus' => null, 'haspreview' => 0, 'jpgstatus' => 0, 'groups_id' => null,
            'fromname' => 'Poster', 'isrenamed' => 1, 'additional_pp_claim_token' => null, 'imdbid' => null,
            'videos_id' => null, 'tv_episodes_id' => null, 'musicinfo_id' => null, 'consoleinfo_id' => null,
            'gamesinfo_id' => null, 'bookinfo_id' => null, 'anidbid' => null,
        ];
    }
}
