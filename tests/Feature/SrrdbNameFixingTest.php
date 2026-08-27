<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Services\NameFixing\NameFixingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class SrrdbNameFixingTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'descriptive_title_rename' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIsolatedDatabase();
        config([
            'nntmux.echocli' => false,
            'nntmux_srrdb.enabled' => true,
            'nntmux_srrdb.max_requests_per_cycle' => 25,
            'nntmux_srrdb.requests_per_second' => 0,
            'nntmux_srrdb.retry_attempts' => 2,
            'nntmux_srrdb.backoff_milliseconds' => 0,
            'nntmux_srrdb.circuit_breaker_failures' => 2,
        ]);

        Event::fake([ReleaseNameFixed::class]);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_confirmed_archive_crc_match_renames_and_records_predb_metadata(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.part01.rar', 900_000, '0053CA13');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response($this->detailsResponse('0053CA13', 900_000), 200),
        ]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $predb = DB::table('predb')->where('id', $release->predb_id)->first();
        $this->assertSame('Example.Movie.2026.1080p-GROUP', $release->searchname);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->is_trusted_name);
        $this->assertSame(1, (int) $release->proc_srrdb);
        $this->assertSame('1234567', $release->imdbid);
        $this->assertSame('srrdb', $predb->source);
        $this->assertSame('Example.Movie.2026.1080p-GROUP', $predb->title);
        $this->assertSame('2026-08-15 12:34:56', $predb->predate);
        $this->assertSame('1000000', $predb->size);
        $this->assertSame('1', $predb->nfo);
        $this->assertSame('Movies', $predb->category);
        $this->assertSame(0, (int) $predb->requestid);
    }

    public function test_details_file_mismatch_does_not_rename_and_marks_ambiguous(): void
    {
        $originalName = 'f9fba2f7697a4ea996423fd2b65b896a';
        $this->insertRelease(1, $originalName);
        $this->insertFile(1, 'movie.part01.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response($this->detailsResponse('DEADBEEF', 900_000), 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($originalName, $release->searchname);
        $this->assertSame(NameFixingService::PROC_SRRDB_AMBIGUOUS, (int) $release->proc_srrdb);
        $this->assertSame(0, DB::table('predb')->count());
    }

    public function test_zero_results_are_negatively_cached_for_a_second_release(): void
    {
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response(['resultsCount' => 0, 'results' => []], 200),
        ]);
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'first.rar', 900_000, 'AABBCCDD');

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $this->insertRelease(2, 'e46d5fba11c948c2841cc83da1ff7afd');
        $this->insertFile(2, 'second.rar', 900_000, 'AABBCCDD');
        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('srrdb_lookups', ['crc32' => 'AABBCCDD', 'status' => 'no_match']);
        $this->assertSame(1, (int) DB::table('releases')->where('id', 2)->value('proc_srrdb'));
    }

    public function test_confirmed_results_are_positively_cached_for_a_second_release(): void
    {
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response($this->detailsResponse('AABBCCDD', 900_000), 200),
        ]);
        foreach ([1, 2] as $id) {
            $this->insertRelease($id, str_repeat((string) $id, 32));
            $this->insertFile($id, "movie-{$id}.rar", 900_000, 'AABBCCDD');
        }
        Search::shouldReceive('updateRelease')->twice();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('releases')->where('searchname', 'Example.Movie.2026.1080p-GROUP')->count());
        $this->assertDatabaseHas('srrdb_lookups', ['crc32' => 'AABBCCDD', 'status' => 'match']);
    }

    public function test_only_the_unique_details_verified_search_result_is_accepted(): void
    {
        $search = $this->searchResponse();
        $search['results'][] = array_merge($search['results'][0], [
            'release' => 'Wrong.Movie.2026.1080p-GROUP',
        ]);
        $search['resultsCount'] = 2;
        Http::fake(function (Request $request) use ($search) {
            if (str_contains($request->url(), '/search/')) {
                return Http::response($search, 200);
            }

            if (str_contains($request->url(), rawurlencode('Wrong.Movie.2026.1080p-GROUP'))) {
                return Http::response($this->detailsResponse('DEADBEEF', 900_000), 200);
            }

            return Http::response($this->detailsResponse('AABBCCDD', 900_000), 200);
        });
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Search::shouldReceive('updateRelease')->once()->with(1);

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $this->assertSame('Example.Movie.2026.1080p-GROUP', DB::table('releases')->where('id', 1)->value('searchname'));
        Http::assertSentCount(3);
    }

    public function test_release_with_an_existing_trusted_name_is_not_a_candidate(): void
    {
        $this->insertRelease(1, 'Already.Trusted.2026.1080p-GROUP');
        DB::table('releases')->where('id', 1)->update(['is_trusted_name' => 1]);
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertNothingSent();
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_srrdb'));
    }

    public function test_primary_archive_is_looked_up_before_a_larger_sample_and_stops_on_match(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'sample.mkv', 950_000, 'DEADBEEF');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response($this->detailsResponse('AABBCCDD', 900_000), 200),
        ]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'archive-crc:AABBCCDD'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'DEADBEEF'));
    }

    public function test_later_distinct_crc_can_confirm_after_an_ambiguous_primary_archive(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 800_000, 'AAAAAAAA');
        $this->insertFile(1, 'movie.mkv', 900_000, 'BBBBBBBB');
        Http::fakeSequence()
            ->push($this->searchResponse(), 200)
            ->push($this->detailsResponse('BBBBBBBB', 900_000), 200)
            ->push($this->searchResponse(), 200)
            ->push($this->detailsResponse('BBBBBBBB', 900_000), 200);
        Search::shouldReceive('updateRelease')->once()->with(1);

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame('Example.Movie.2026.1080p-GROUP', $release->searchname);
        $this->assertSame(NameFixingService::PROC_SRRDB_DONE, (int) $release->proc_srrdb);
        Http::assertSentCount(4);
    }

    public function test_dry_run_does_not_create_predb_or_change_the_release(): void
    {
        $originalName = 'f9fba2f7697a4ea996423fd2b65b896a';
        $this->insertRelease(1, $originalName);
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response($this->detailsResponse('AABBCCDD', 900_000), 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, false, 2, true, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($originalName, $release->searchname);
        $this->assertSame(0, (int) $release->proc_srrdb);
        $this->assertSame(0, DB::table('predb')->count());
        $this->assertDatabaseHas('srrdb_lookups', ['crc32' => 'AABBCCDD', 'status' => 'match']);
    }

    public function test_transient_failures_open_the_circuit_without_renaming_or_throwing(): void
    {
        Http::fakeSequence()
            ->push([], 429)
            ->push([], 500)
            ->whenEmpty(Http::response($this->searchResponse(), 200));
        foreach ([1, 2, 3] as $id) {
            $this->insertRelease($id, str_repeat((string) $id, 32));
            $this->insertFile($id, "movie-{$id}.rar", 900_000, sprintf('%08X', $id));
        }
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('releases')->where('isrenamed', 1)->count());
        $this->assertSame(0, DB::table('releases')->where('proc_srrdb', '!=', 0)->count());
    }

    public function test_malformed_success_response_remains_pending_without_poisoning_cache(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response('<html>temporary proxy response</html>', 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_srrdb'));
        $this->assertSame(0, DB::table('srrdb_lookups')->count());
    }

    public function test_malformed_details_response_remains_pending_without_poisoning_cache(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response(['error' => 'temporary upstream response'], 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_srrdb'));
        $this->assertSame(0, DB::table('srrdb_lookups')->count());
    }

    public function test_unknown_release_details_response_is_authoritative_ambiguity(): void
    {
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($this->searchResponse(), 200),
            'api.srrdb.com/v1/details/*' => Http::response([], 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $this->assertSame(NameFixingService::PROC_SRRDB_AMBIGUOUS, (int) DB::table('releases')->where('id', 1)->value('proc_srrdb'));
        $this->assertDatabaseHas('srrdb_lookups', ['crc32' => 'AABBCCDD', 'status' => 'ambiguous']);
    }

    public function test_partial_paginated_results_are_ambiguous_and_never_trusted(): void
    {
        $search = $this->searchResponse();
        $search['resultsCount'] = 2;
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response($search, 200),
        ]);
        Search::shouldReceive('updateRelease')->never();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(0, (int) $release->isrenamed);
        $this->assertSame(NameFixingService::PROC_SRRDB_AMBIGUOUS, (int) $release->proc_srrdb);
        $this->assertDatabaseHas('srrdb_lookups', ['crc32' => 'AABBCCDD', 'status' => 'ambiguous']);
        Http::assertSentCount(1);
    }

    public function test_feature_is_disabled_by_default(): void
    {
        config(['nntmux_srrdb.enabled' => false]);
        $this->insertRelease(1, 'f9fba2f7697a4ea996423fd2b65b896a');
        $this->insertFile(1, 'movie.rar', 900_000, 'AABBCCDD');
        Http::fake();

        app(NameFixingService::class)->fixNamesWithSrrdb(2, true, 2, true, false);

        Http::assertNothingSent();
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_srrdb'));
    }

    /**
     * @return array<string, mixed>
     */
    private function searchResponse(): array
    {
        return [
            'resultsCount' => 1,
            'results' => [[
                'release' => 'Example.Movie.2026.1080p-GROUP',
                'date' => '2026-08-15 12:34:56',
                'size' => 1_000_000,
                'hasNFO' => 'yes',
                'imdbId' => '1234567',
                'category' => 'Movies',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsResponse(string $crc, int $size): array
    {
        return [
            'name' => 'Example.Movie.2026.1080p-GROUP',
            'files' => [[
                'name' => 'movie.mkv',
                'size' => $size,
                'crc' => $crc,
            ]],
        ];
    }

    private function insertRelease(int $id, string $searchName): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => $searchName,
            'searchname' => $searchName,
            'fromname' => 'poster@example.test',
            'groups_id' => 1,
            'categories_id' => Category::OTHER_HASHED,
            'size' => 1_000_000,
            'completion' => 100,
            'guid' => str_pad((string) $id, 40, '0'),
            'leftguid' => substr((string) $id, 0, 1),
            'predb_id' => 0,
            'isrenamed' => 0,
            'is_trusted_name' => 0,
            'adddate' => now(),
        ]);
    }

    private function insertFile(int $releaseId, string $name, int $size, string $crc): void
    {
        DB::table('release_files')->insert([
            'releases_id' => $releaseId,
            'name' => $name,
            'size' => $size,
            'crc32' => $crc,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('searchname');
            $table->string('searchname_normalized')->nullable();
            $table->string('display_name')->nullable();
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->double('completion')->default(0);
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->dateTime('adddate')->nullable();
            $table->unsignedInteger('predb_id')->default(0);
            $table->unsignedInteger('anidbid')->nullable();
            $table->boolean('isrenamed')->default(false);
            $table->boolean('is_trusted_name')->default(false);
            $table->boolean('iscategorized')->default(false);
            $table->integer('nfostatus')->default(0);
            $table->integer('proc_nfo')->default(0);
            $table->integer('proc_files')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('proc_uid')->default(0);
            $table->integer('proc_srr')->default(0);
            $table->integer('proc_hash16k')->default(0);
            $table->integer('proc_crc32')->default(0);
            $table->integer('proc_srrdb')->default(0);
            $table->unsignedInteger('videos_id')->default(0);
            $table->unsignedInteger('tv_episodes_id')->default(0);
            $table->unsignedInteger('movieinfo_id')->nullable();
            $table->string('imdbid')->nullable();
            $table->unsignedInteger('musicinfo_id')->nullable();
            $table->unsignedInteger('consoleinfo_id')->nullable();
            $table->unsignedInteger('bookinfo_id')->nullable();
            $table->integer('gamesinfo_id')->default(0);
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('crc32')->default('');
        });

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->unique();
            $table->string('nfo')->nullable();
            $table->string('size')->nullable();
            $table->string('category')->nullable();
            $table->dateTime('predate')->nullable();
            $table->string('source')->default('');
            $table->unsignedInteger('requestid')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->boolean('nuked')->default(false);
            $table->string('nukereason')->nullable();
            $table->string('files')->nullable();
            $table->string('filename')->default('');
            $table->boolean('searched')->default(false);
        });

        Schema::create('srrdb_lookups', function (Blueprint $table): void {
            $table->string('crc32', 8)->primary();
            $table->string('status', 20);
            $table->json('payload')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }
}
