<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Controllers\Admin\AdminReleasesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdminReleasesControllerEditTest extends TestCase
{
    use IsolatedSqliteDatabase;

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

        Cache::flush();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_submit_with_nonexistent_category_is_rejected_and_modifies_nothing(): void
    {
        $releaseId = $this->seedRelease();

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'category' => '999999',
        ]));

        try {
            app(AdminReleasesController::class)->edit($request);
            $this->fail('Expected a ValidationException for a nonexistent category.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }

        $release = DB::table('releases')->where('id', $releaseId)->first();
        $this->assertSame('Original.Release.Name', $release->name);
        $this->assertSame(5000, (int) $release->categories_id);
    }

    public function test_submit_with_non_numeric_category_is_rejected_and_modifies_nothing(): void
    {
        $releaseId = $this->seedRelease();

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'category' => 'not-a-category',
        ]));

        try {
            app(AdminReleasesController::class)->edit($request);
            $this->fail('Expected a ValidationException for a non-numeric category.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }

        $this->assertSame(5000, (int) DB::table('releases')->where('id', $releaseId)->value('categories_id'));
    }

    public function test_submit_with_non_numeric_ids_is_rejected(): void
    {
        $releaseId = $this->seedRelease();

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'videos_id' => 'abc',
            'tv_episodes_id' => 'def',
            'anidbid' => 'ghi',
            'grabs' => 'jkl',
        ]));

        try {
            app(AdminReleasesController::class)->edit($request);
            $this->fail('Expected a ValidationException for non-numeric ids.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey('videos_id', $errors);
            $this->assertArrayHasKey('tv_episodes_id', $errors);
            $this->assertArrayHasKey('anidbid', $errors);
            $this->assertArrayHasKey('grabs', $errors);
        }

        $this->assertSame('Original.Release.Name', DB::table('releases')->where('id', $releaseId)->value('name'));
    }

    public function test_submit_with_missing_name_is_rejected(): void
    {
        $releaseId = $this->seedRelease();

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'name' => '',
        ]));

        try {
            app(AdminReleasesController::class)->edit($request);
            $this->fail('Expected a ValidationException for a missing name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }
    }

    public function test_valid_submit_updates_the_release_and_redirects_to_details(): void
    {
        Search::shouldReceive('updateRelease')->andReturnNull();

        $releaseId = $this->seedRelease();

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'name' => 'Updated.Release.Name',
            'searchname' => 'Updated Release Name',
            'category' => '5040',
        ]));

        $response = app(AdminReleasesController::class)->edit($request);

        $this->assertTrue($response->isRedirect());
        $this->assertStringContainsString('details/test-guid-123', $response->headers->get('Location'));

        $release = DB::table('releases')->where('id', $releaseId)->first();
        $this->assertSame('Updated.Release.Name', $release->name);
        $this->assertSame('Updated Release Name', $release->searchname);
        $this->assertSame(5040, (int) $release->categories_id);
        $this->assertSame(7, (int) $release->grabs);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(int $releaseId, array $overrides = []): array
    {
        return array_merge([
            'action' => 'submit',
            'id' => (string) $releaseId,
            'guid' => 'test-guid-123',
            'name' => 'Original.Release.Name',
            'searchname' => 'Original Release Name',
            'fromname' => 'poster@example.com',
            'category' => '5000',
            'totalpart' => '10',
            'grabs' => '7',
            'size' => '1073741824',
            'postdate' => '2026-08-01T10:00',
            'adddate' => '2026-08-02T11:30',
            'videos_id' => '0',
            'tv_episodes_id' => '0',
            'imdbid' => '',
            'anidbid' => '0',
        ], $overrides);
    }

    public function test_valid_submit_restores_an_owed_preview_when_the_new_root_has_generation_enabled(): void
    {
        Search::shouldReceive('updateRelease')->andReturnNull();

        $releaseId = $this->seedRelease();
        DB::table('releases')->where('id', $releaseId)->update(['haspreview' => -2, 'passwordstatus' => 0]);

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'category' => '5040',
        ]));

        $response = app(AdminReleasesController::class)->edit($request);

        $this->assertTrue($response->isRedirect());
        $this->assertSame(-1, (int) DB::table('releases')->where('id', $releaseId)->value('haspreview'));
    }

    public function test_valid_submit_keeps_the_skip_sentinel_when_the_new_root_has_generation_disabled(): void
    {
        Search::shouldReceive('updateRelease')->andReturnNull();

        DB::table('root_categories')->where('id', 5000)->update(['generate_previews' => 0]);

        $releaseId = $this->seedRelease();
        DB::table('releases')->where('id', $releaseId)->update(['haspreview' => -2, 'passwordstatus' => 0]);

        $request = Request::create('/admin/release-edit', 'POST', $this->payload($releaseId, [
            'category' => '5040',
        ]));

        app(AdminReleasesController::class)->edit($request);

        $this->assertSame(-2, (int) DB::table('releases')->where('id', $releaseId)->value('haspreview'));
    }

    private function seedRelease(): int
    {
        return (int) DB::table('releases')->insertGetId([
            'guid' => 'test-guid-123',
            'name' => 'Original.Release.Name',
            'searchname' => 'Original Release Name',
            'fromname' => 'poster@example.com',
            'categories_id' => 5000,
            'totalpart' => 10,
            'grabs' => 7,
            'size' => 1073741824,
            'postdate' => '2026-08-01 10:00:00',
            'adddate' => '2026-08-02 11:30:00',
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'imdbid' => null,
            'anidbid' => null,
            'movieinfo_id' => null,
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

        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('guid');
                $table->string('name')->default('');
                $table->string('searchname')->default('');
                $table->string('fromname')->nullable();
                $table->integer('categories_id')->default(10);
                $table->integer('totalpart')->nullable();
                $table->unsignedInteger('grabs')->default(0);
                $table->unsignedBigInteger('size')->default(0);
                $table->dateTime('postdate')->nullable();
                $table->dateTime('adddate')->nullable();
                $table->unsignedInteger('videos_id')->default(0);
                $table->integer('tv_episodes_id')->default(0);
                $table->string('imdbid', 100)->nullable();
                $table->integer('anidbid')->nullable();
                $table->integer('movieinfo_id')->nullable();
                $table->integer('haspreview')->default(0);
                $table->integer('passwordstatus')->default(0);
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->integer('root_categories_id')->default(0);
            });
        }

        if (! Schema::hasTable('root_categories')) {
            Schema::create('root_categories', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('title')->default('');
                $table->boolean('generate_previews')->default(true);
            });
        }

        DB::table('categories')->insertOrIgnore([
            ['id' => 5000, 'title' => 'TV', 'root_categories_id' => 5000],
            ['id' => 5040, 'title' => 'TV HD', 'root_categories_id' => 5000],
        ]);

        DB::table('root_categories')->insertOrIgnore([
            ['id' => 5000, 'title' => 'TV', 'generate_previews' => 1],
        ]);
    }
}
