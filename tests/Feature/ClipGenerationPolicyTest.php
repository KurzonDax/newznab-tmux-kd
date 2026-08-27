<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Releases\ClipGenerationPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class ClipGenerationPolicyTest extends TestCase
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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_only_eligible_roots_with_the_toggle_on_get_clips(): void
    {
        $policy = new ClipGenerationPolicy;

        $this->assertTrue($policy->enabledForCategory(6010), 'XXX has the toggle on.');
        $this->assertTrue($policy->enabledForCategory(5040), 'TV has the toggle on.');
        $this->assertFalse($policy->enabledForCategory(2040), 'Movies has the toggle off.');
        $this->assertFalse(
            $policy->enabledForCategory(Category::BOOKS_MAGAZINES),
            'Ineligible roots never get Clips, whatever the column says.'
        );
    }

    public function test_unknown_and_rootless_categories_default_to_off(): void
    {
        DB::table('categories')->insert([
            ['id' => 7777, 'title' => 'Orphan', 'root_categories_id' => null],
        ]);

        $policy = new ClipGenerationPolicy;

        $this->assertFalse($policy->enabledForCategory(999999), 'Unknown categories keep the transcode.');
        $this->assertFalse($policy->enabledForCategory(7777), 'Rootless categories keep the transcode.');
    }

    private function createSchema(): void
    {
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->boolean('generate_clips')->default(false);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('root_categories_id')->nullable();
        });

        DB::table('root_categories')->insert([
            ['id' => Category::MOVIE_ROOT, 'title' => 'Movies', 'generate_clips' => 0],
            ['id' => Category::TV_ROOT, 'title' => 'TV', 'generate_clips' => 1],
            ['id' => Category::XXX_ROOT, 'title' => 'XXX', 'generate_clips' => 1],
            // The Books column is deliberately on: eligibility must win.
            ['id' => Category::BOOKS_ROOT, 'title' => 'Books', 'generate_clips' => 1],
        ]);

        DB::table('categories')->insert([
            ['id' => 2040, 'title' => 'Movies HD', 'root_categories_id' => Category::MOVIE_ROOT],
            ['id' => 5040, 'title' => 'TV HD', 'root_categories_id' => Category::TV_ROOT],
            ['id' => 6010, 'title' => 'XXX DVD', 'root_categories_id' => Category::XXX_ROOT],
            ['id' => Category::BOOKS_MAGAZINES, 'title' => 'Books Magazines', 'root_categories_id' => Category::BOOKS_ROOT],
        ]);
    }
}
