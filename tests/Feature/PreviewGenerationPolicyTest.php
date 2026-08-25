<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Services\AdditionalProcessing\Config\PasswordInspectionMode;
use App\Services\BookService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class PreviewGenerationPolicyTest extends TestCase
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
            'maxbooksprocessed' => '50',
            'amazonsleep' => '0',
            'lookupbooks' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'nntmux.echocli' => false,
        ]);

        Cache::flush();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_generation_follows_the_leaf_categories_root_toggle(): void
    {
        $policy = new PreviewGenerationPolicy;

        $this->assertTrue($policy->generationEnabledForCategory(2040), 'Movies root has generation enabled.');
        $this->assertFalse($policy->generationEnabledForCategory(6010), 'XXX root has generation disabled.');
        $this->assertTrue($policy->generationEnabledForCategory(999999), 'Unknown categories stay enabled.');
    }

    public function test_disabled_category_ids_cover_only_roots_with_generation_off(): void
    {
        $ids = (new PreviewGenerationPolicy)->categoryIdsWithGenerationDisabled();

        $this->assertSame([6010], $ids);
    }

    public function test_restore_treats_rootless_categories_as_enabled(): void
    {
        Search::shouldReceive('updateRelease')->andReturnNull();

        DB::table('categories')->insert([
            ['id' => 7777, 'title' => 'Orphan', 'root_categories_id' => null],
        ]);
        DB::table('releases')->insert([
            $this->releaseRow(1, 7777, hasPreview: -2, passwordStatus: 0),
        ]);

        $this->assertSame(1, (new PreviewGenerationPolicy)->restoreOwedPreviews([1]));
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    public function test_restore_flips_skipped_releases_in_enabled_roots_back_to_pending(): void
    {
        $this->setPasswordInspection(true);
        Search::shouldReceive('updateRelease')->andReturnNull();

        DB::table('releases')->insert([
            // Skipped by policy, now in an enabled root: owed regeneration.
            $this->releaseRow(1, 2040, hasPreview: -2, passwordStatus: 0),
            // Skipped by policy but in a disabled root: keeps the sentinel.
            $this->releaseRow(2, 6010, hasPreview: -2, passwordStatus: 0),
            // Attempted-none (0) is never flipped by a category move.
            $this->releaseRow(3, 2040, hasPreview: 0, passwordStatus: 0),
            // Already has a preview: untouched.
            $this->releaseRow(4, 2040, hasPreview: 1, passwordStatus: 0),
        ]);

        $flipped = (new PreviewGenerationPolicy)->restoreOwedPreviews([1, 2, 3, 4]);

        $this->assertSame(1, $flipped);

        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(
            PasswordInspectionMode::pendingReleaseStatus(),
            (int) DB::table('releases')->where('id', 1)->value('passwordstatus')
        );
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('pp_timeout_count'));

        $this->assertSame(-2, (int) DB::table('releases')->where('id', 2)->value('haspreview'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 3)->value('haspreview'));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 4)->value('haspreview'));
    }

    public function test_restore_uses_the_mode_aware_pending_password_status_when_inspection_is_off(): void
    {
        $this->setPasswordInspection(false);
        Search::shouldReceive('updateRelease')->andReturnNull();

        DB::table('releases')->insert([
            $this->releaseRow(1, 2040, hasPreview: -2, passwordStatus: 1),
        ]);

        $flipped = (new PreviewGenerationPolicy)->restoreOwedPreviews([1]);

        $this->assertSame(1, $flipped);
        $this->assertSame(0, PasswordInspectionMode::pendingReleaseStatus());
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('passwordstatus'));
    }

    public function test_restore_ignores_empty_and_invalid_ids(): void
    {
        $this->assertSame(0, (new PreviewGenerationPolicy)->restoreOwedPreviews([]));
        $this->assertSame(0, (new PreviewGenerationPolicy)->restoreOwedPreviews([0, -5]));
    }

    public function test_bulk_recategorize_endpoint_service_flips_owed_previews(): void
    {
        $this->setPasswordInspection(true);
        Search::shouldReceive('updateRelease')->andReturnNull();

        DB::table('releases')->insert([
            $this->releaseRow(1, 6010, hasPreview: -2, passwordStatus: 0),
        ]);

        $updated = (new ReleaseManagementService)->bulkUpdateCategory(['guid-1'], 2040);

        $this->assertSame(1, $updated);
        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(2040, (int) $row->categories_id);
        $this->assertSame(-1, (int) $row->haspreview, 'Bulk recategorize into an enabled root flips the sentinel.');
        $this->assertSame(-1, (int) $row->passwordstatus);
    }

    public function test_book_junk_recategorization_restores_an_owed_preview(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes()->andReturnNull();

        DB::table('releases')->insert([
            $this->releaseRow(1, 6010, hasPreview: -2, passwordStatus: 0),
        ]);

        $this->assertFalse((new BookService)->parseTitle('ArtofUsenet', 1, 'ebook'));

        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(Category::BOOKS_UNKNOWN, (int) $row->categories_id);
        $this->assertSame(-1, (int) $row->haspreview);
    }

    public function test_book_magazine_recategorization_restores_an_owed_preview(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes()->andReturnNull();

        DB::table('releases')->insert([
            $this->releaseRow(1, 6010, hasPreview: -2, passwordStatus: 0),
        ]);

        $this->assertFalse((new BookService)->parseTitle('MCN April 22, 2026', 1, 'ebook'));

        $row = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(Category::BOOKS_MAGAZINES, (int) $row->categories_id);
        $this->assertSame(-1, (int) $row->haspreview);
    }

    private function setPasswordInspection(bool $enabled): void
    {
        config([
            'nntmux_settings.check_passworded_rars' => $enabled,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function releaseRow(int $id, int $categoryId, int $hasPreview, int $passwordStatus): array
    {
        return [
            'id' => $id,
            'guid' => 'guid-'.$id,
            'categories_id' => $categoryId,
            'haspreview' => $hasPreview,
            'passwordstatus' => $passwordStatus,
        ];
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->boolean('generate_previews')->default(true);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('root_categories_id')->nullable();
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('categories_id');
            $table->integer('haspreview')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->unsignedInteger('pp_timeout_count')->default(2);
            $table->integer('iscategorized')->default(0);
            $table->string('searchname')->default('');
            $table->string('searchname_normalized')->nullable();
            $table->integer('isrenamed')->default(0);
            $table->integer('is_trusted_name')->default(0);
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
        });

        DB::table('root_categories')->insert([
            ['id' => 2000, 'title' => 'Movies', 'generate_previews' => 1],
            ['id' => 5000, 'title' => 'TV', 'generate_previews' => 1],
            ['id' => 6000, 'title' => 'XXX', 'generate_previews' => 0],
            ['id' => Category::BOOKS_ROOT, 'title' => 'Books', 'generate_previews' => 1],
        ]);

        DB::table('categories')->insert([
            ['id' => 2040, 'title' => 'Movies HD', 'root_categories_id' => 2000],
            ['id' => 2080, 'title' => 'Movies WEB-DL', 'root_categories_id' => 2000],
            ['id' => 5040, 'title' => 'TV HD', 'root_categories_id' => 5000],
            ['id' => 6010, 'title' => 'XXX DVD', 'root_categories_id' => 6000],
            ['id' => Category::BOOKS_MAGAZINES, 'title' => 'Books Magazines', 'root_categories_id' => Category::BOOKS_ROOT],
            ['id' => Category::BOOKS_UNKNOWN, 'title' => 'Books Other', 'root_categories_id' => Category::BOOKS_ROOT],
        ]);
    }
}
