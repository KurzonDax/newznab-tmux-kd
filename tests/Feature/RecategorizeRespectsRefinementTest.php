<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\ReleaseRecategorizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\TestCase;

/** nntmux:recategorize-releases refines the pipeline category as a rename would (#1120, decision 6). */
class RecategorizeRespectsRefinementTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createNameWriteTables();
        Search::shouldReceive('updateRelease')->andReturnTrue();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_refined_xxx_release_is_not_moved_back_to_xxx_other(): void
    {
        $this->pipelineFiles(Category::XXX_OTHER);
        $id = $this->linkedRelease(1, ['categories_id' => Category::XXX_X264, 'iscategorized' => 1]);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);

        $this->artisan('nntmux:recategorize-releases', ['--category' => Category::XXX_X264])->assertSuccessful();

        $this->assertSame(Category::XXX_X264, (int) $this->releaseRow($id)['categories_id']);
    }

    public function test_the_pipeline_category_is_refined_from_the_stored_media_info(): void
    {
        $this->pipelineFiles(Category::MOVIE_OTHER);
        $id = $this->linkedRelease(1, ['categories_id' => Category::MOVIE_HD]);
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => 'HEVC', 'videowidth' => 1920, 'videoheight' => 1080]);

        $this->assertSame(Category::MOVIE_X265, (new ReleaseRecategorizer)->categoryFor(Release::query()->findOrFail($id)));
    }

    public function test_an_ineligible_pipeline_category_is_returned_without_reading_media_info(): void
    {
        $this->pipelineFiles(Category::MOVIE_HD);
        $id = $this->linkedRelease(1, ['categories_id' => Category::MOVIE_OTHER]);
        $release = Release::query()->findOrFail($id);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(Category::MOVIE_HD, (new ReleaseRecategorizer)->categoryFor($release));

        $this->assertSame([], $queries);
    }

    private function pipelineFiles(int $categoryId): void
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->andReturn(['categories_id' => $categoryId]);
        $this->app->instance(CategorizationService::class, $categorization);
    }
}
