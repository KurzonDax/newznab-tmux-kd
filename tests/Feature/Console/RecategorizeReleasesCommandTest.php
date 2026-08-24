<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecategorizeReleasesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('route_obfuscated_names')->default(false);
            $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->boolean('generate_previews')->default(true);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('root_categories_id');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->string('fromname');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id');
            $table->boolean('iscategorized')->default(false);
            $table->unsignedInteger('videos_id')->default(0);
            $table->unsignedInteger('tv_episodes_id')->default(0);
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('bookinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->integer('haspreview')->default(0);
            $table->integer('passwordstatus')->default(0);
        });

        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.test',
        ]);
        DB::table('root_categories')->insert([
            ['id' => Category::OTHER_ROOT, 'title' => 'Other'],
            ['id' => Category::MOVIE_ROOT, 'title' => 'Movies'],
            ['id' => Category::BOOKS_ROOT, 'title' => 'Books'],
        ]);
        DB::table('categories')->insert([
            ['id' => Category::OTHER_MISC, 'title' => 'Misc', 'root_categories_id' => Category::OTHER_ROOT],
            ['id' => Category::MOVIE_HD, 'title' => 'HD', 'root_categories_id' => Category::MOVIE_ROOT],
            ['id' => Category::BOOKS_EBOOK, 'title' => 'Ebook', 'root_categories_id' => Category::BOOKS_ROOT],
        ]);
    }

    public function test_all_finalizes_changed_and_unchanged_releases_in_other_and_non_other_categories(): void
    {
        DB::table('releases')->insert([
            $this->release(1, 'George.Orwell.1984.Novel.PDF', Category::OTHER_MISC, 41),
            $this->release(2, 'Unknown.Upload.Name', Category::OTHER_MISC, 42),
            $this->release(3, 'George.Orwell.1984.Novel.PDF', Category::MOVIE_HD, 43),
            $this->release(4, 'George.Orwell.1984.Novel.PDF', Category::BOOKS_EBOOK, 44),
        ]);
        DB::table('releases')->whereIn('id', [2, 4])->update(['iscategorized' => 0]);

        Search::shouldReceive('updateRelease')->once()->with(1);
        Search::shouldReceive('updateRelease')->once()->with(3);

        $this->artisan('nntmux:recategorize-releases', ['--all' => true])
            ->expectsConfirmation(
                'This will reset categorization on all releases and re-categorize them all from scratch. Are you sure? (y/n)',
                'yes',
            )
            ->assertSuccessful();

        $releases = DB::table('releases')->orderBy('id')->get()->keyBy('id');

        foreach ($releases as $release) {
            $this->assertSame(1, (int) $release->iscategorized);
        }

        $this->assertSame(Category::BOOKS_EBOOK, (int) $releases[1]->categories_id);
        $this->assertNull($releases[1]->bookinfo_id);
        $this->assertSame(Category::OTHER_MISC, (int) $releases[2]->categories_id);
        $this->assertSame(42, (int) $releases[2]->bookinfo_id);
        $this->assertSame(Category::BOOKS_EBOOK, (int) $releases[3]->categories_id);
        $this->assertNull($releases[3]->bookinfo_id);
        $this->assertSame(Category::BOOKS_EBOOK, (int) $releases[4]->categories_id);
        $this->assertSame(44, (int) $releases[4]->bookinfo_id);
    }

    public function test_all_test_reports_changes_without_writing_any_release_state(): void
    {
        DB::table('releases')->insert([
            $this->release(1, 'George.Orwell.1984.Novel.PDF', Category::OTHER_MISC, 41),
            $this->release(2, 'Unknown.Upload.Name', Category::OTHER_MISC, 42),
            $this->release(3, 'George.Orwell.1984.Novel.PDF', Category::BOOKS_EBOOK, 43),
        ]);
        DB::table('releases')->where('id', 2)->update(['iscategorized' => 0]);

        $before = DB::table('releases')->orderBy('id')->get()->map(
            static fn (object $release): array => (array) $release,
        )->all();

        Search::shouldReceive('updateRelease')->never();

        $this->artisan('nntmux:recategorize-releases', [
            '--all' => true,
            '--test' => true,
        ])
            ->expectsOutputToContain('Would have changed George.Orwell.1984.Novel.PDF from 10 to 7020')
            ->expectsOutputToContain('Would have finalized Unknown.Upload.Name as categorized')
            ->assertSuccessful();

        $after = DB::table('releases')->orderBy('id')->get()->map(
            static fn (object $release): array => (array) $release,
        )->all();

        $this->assertSame($before, $after);
    }

    /**
     * @return array<string, int|string>
     */
    private function release(int $id, string $searchName, int $categoryId, int $bookInfoId): array
    {
        return [
            'id' => $id,
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
            'bookinfo_id' => $bookInfoId,
            'anidbid' => 4,
        ];
    }
}
