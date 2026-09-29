<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Categorization;

use App\Facades\Search;
use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use App\Services\Releases\PreviewGenerationPolicy;
use Database\Seeders\CategoriesTableSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class AdultCategoryRowsTest extends TestCase
{
    private const string VR_MIGRATION = '2026_09_29_000000_add_xxx_vr_category_where_missing.php';

    private const string ONLYFANS_MIGRATION = '2026_09_29_000100_refile_onlyfans_releases.php';

    private const int ONLYFANS = 6047;

    protected function setUp(): void
    {
        parent::setUp();

        $tables = ProductionTables::fromAuthority();
        foreach (['settings', 'usenet_groups', 'root_categories', 'categories', 'releases', 'releases_groups'] as $table) {
            $tables->create($table);
        }

        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
    }

    public function test_seeding_the_categories_creates_the_vr_row_under_xxx(): void
    {
        $this->seed(CategoriesTableSeeder::class);

        $vr = DB::table('categories')->where('id', Category::XXX_VR)->first();

        $this->assertNotNull($vr);
        $this->assertSame('VR', $vr->title);
        $this->assertSame(Category::XXX_ROOT, (int) $vr->root_categories_id);
        $this->assertSame(1, (int) $vr->status);
        $this->assertNull($vr->description);
        $this->assertSame(0, (int) $vr->minsizetoformrelease);
        $this->assertSame(0, (int) $vr->maxsizetoformrelease);
        $this->assertSame(0, DB::table('categories')->where('id', self::ONLYFANS)->count());
    }

    public function test_the_vr_migration_adds_the_missing_row_once(): void
    {
        $this->insertXxxRoot();
        $migration = require database_path('migrations/'.self::VR_MIGRATION);

        $migration->up();
        $migration->up();

        $rows = DB::table('categories')->where('id', Category::XXX_VR)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('VR', $rows[0]->title);
        $this->assertSame(Category::XXX_ROOT, (int) $rows[0]->root_categories_id);
        $this->assertSame(1, (int) $rows[0]->status);
    }

    public function test_the_vr_migration_leaves_an_existing_row_alone(): void
    {
        $this->insertXxxRoot();
        DB::table('categories')->insert([
            'id' => Category::XXX_VR, 'title' => 'Virtual Reality', 'root_categories_id' => Category::XXX_ROOT,
            'status' => 2, 'description' => 'Renamed by the admin', 'minsizetoformrelease' => 5, 'maxsizetoformrelease' => 9,
        ]);
        $before = (array) DB::table('categories')->find(Category::XXX_VR);

        (require database_path('migrations/'.self::VR_MIGRATION))->up();

        $this->assertSame($before, (array) DB::table('categories')->find(Category::XXX_VR));
    }

    public function test_the_vr_migration_leaves_the_row_to_the_seeder_while_the_xxx_root_is_missing(): void
    {
        (require database_path('migrations/'.self::VR_MIGRATION))->up();

        $this->assertSame(0, DB::table('categories')->count());
    }

    public function test_the_onlyfans_migration_refiles_its_releases_and_drops_the_category(): void
    {
        $this->insertXxxRoot();
        DB::table('categories')->insert([
            ['id' => Category::XXX_CLIPHD, 'title' => 'HD Clips', 'root_categories_id' => Category::XXX_ROOT, 'status' => 1],
            ['id' => Category::XXX_OTHER, 'title' => 'Other', 'root_categories_id' => Category::XXX_ROOT, 'status' => 1],
            ['id' => self::ONLYFANS, 'title' => 'OnlyFans', 'root_categories_id' => Category::XXX_ROOT, 'status' => 1],
        ]);
        $name = 'Model.Name.OnlyFans.2024.1080p.mp4';
        $this->insertRelease(1, $name, self::ONLYFANS, PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY);
        $this->insertRelease(2, 'Model.Name.OnlyFans.2024.1080p.mp4', Category::XXX_CLIPHD, 0);
        $untouched = (array) DB::table('releases')->find(2);
        $expected = (int) (new CategorizationService)->determineCategory(1, $name, 'poster@example.com', releaseId: 1)['categories_id'];
        Search::spy();

        (require database_path('migrations/'.self::ONLYFANS_MIGRATION))->up();

        $release = DB::table('releases')->find(1);
        $this->assertNotSame(self::ONLYFANS, $expected);
        $this->assertSame(Category::XXX_ROOT, Category::rootCategoryFor($expected));
        $this->assertSame($expected, (int) $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(0, (int) $release->videos_id);
        $this->assertSame(0, (int) $release->tv_episodes_id);
        $this->assertSame(0, (int) $release->gamesinfo_id);
        $this->assertNull($release->imdbid);
        $this->assertNull($release->musicinfo_id);
        $this->assertNull($release->consoleinfo_id);
        $this->assertNull($release->bookinfo_id);
        $this->assertNull($release->anidbid);
        $this->assertSame(-1, (int) $release->haspreview, 'the preview the old category owed is queued again');
        Search::shouldHaveReceived('updateRelease')->with(1);
        Search::shouldNotHaveReceived('updateRelease', [2]);
        $this->assertSame($untouched, (array) DB::table('releases')->find(2));
        $this->assertSame(0, DB::table('categories')->where('id', self::ONLYFANS)->count());
        $this->assertSame(0, DB::table('releases')->where('categories_id', self::ONLYFANS)->count());
    }

    public function test_the_onlyfans_migration_runs_on_an_install_without_the_category(): void
    {
        Search::spy();

        (require database_path('migrations/'.self::ONLYFANS_MIGRATION))->up();

        Search::shouldNotHaveReceived('updateRelease');
        $this->assertSame(0, DB::table('categories')->count());
    }

    private function insertXxxRoot(): void
    {
        DB::table('root_categories')->insert(['id' => Category::XXX_ROOT, 'title' => 'XXX', 'status' => 1, 'generate_previews' => 1]);
    }

    private function insertRelease(int $id, string $searchName, int $categoryId, int $hasPreview): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => $searchName,
            'searchname' => $searchName,
            'fromname' => 'poster@example.com',
            'groups_id' => 1,
            'categories_id' => $categoryId,
            'iscategorized' => 1,
            'videos_id' => 9,
            'tv_episodes_id' => 8,
            'imdbid' => 'tt1234567',
            'musicinfo_id' => 7,
            'consoleinfo_id' => 6,
            'gamesinfo_id' => 5,
            'bookinfo_id' => 4,
            'anidbid' => 3,
            'haspreview' => $hasPreview,
        ]);
    }
}
