<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MovieLookupOutcome;
use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Listeners\RecategorizeReleaseAfterNameFix;
use App\Models\Category;
use App\Services\BookService;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\MediaInfoRefinementService;
use App\Services\Categorization\ReleaseRecategorizer;
use App\Services\MovieService;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseManagementService;
use App\Services\TvProcessing\TvProcessingPipeline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\TestCase;

/**
 * Records what each category writer changes today (issue #1120, Section 2): the links, the
 * MediaInfo refinement, the forced root, the preview restore and the search sync.
 */
class CategoryChangeCharacterizationTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    private const string HEVC = 'HEVC';

    /** @var list<int> */
    private array $synchronized = [];

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createNameWriteTables();
        config(['nntmux.echocli' => false, 'nntmux.categorization.log' => false]);
        Search::shouldReceive('updateRelease')->andReturnUsing(function (int $releaseId): bool {
            $this->synchronized[] = $releaseId;

            return true;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_rename_listener_files_the_pipeline_category_and_restores_previews(): void
    {
        $id = $this->owedRelease(Category::OTHER_HASHED);

        $this->listener(Category::MOVIE_HD)->handle($this->event($id));

        $this->assertMoved($id, Category::MOVIE_HD, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_the_rename_listener_constrains_an_override_to_the_forced_root_then_refines(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => Category::TV_ROOT]);
        $id = $this->owedRelease(Category::OTHER_HASHED);
        $this->hevcVideo($id);

        $this->listener(null)->handle($this->event($id, Category::MOVIE_HD));

        $this->assertMoved($id, Category::TV_X265, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    public function test_the_rename_listener_only_refines_an_unchanged_category(): void
    {
        $id = $this->owedRelease(Category::TV_OTHER, ['iscategorized' => 1]);
        $this->hevcVideo($id);

        $this->listener(Category::TV_OTHER)->handle($this->event($id));

        $this->assertMoved($id, Category::TV_X265, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id], $this->synchronized);
    }

    /** Decision 5 (#1120): a move inside TV keeps the show links. */
    public function test_recategorizing_within_a_section_keeps_its_links_and_ignores_the_forced_root(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => Category::MOVIE_ROOT]);
        $id = $this->owedRelease(Category::TV_HD);
        $this->hevcVideo($id);
        $recategorizer = $this->recategorizer(Category::TV_OTHER);

        $this->assertSame(1, $recategorizer->refileCategory(Category::TV_HD));

        $this->assertMoved($id, Category::TV_OTHER, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    public function test_recategorizing_leaves_a_release_already_in_its_pipeline_category(): void
    {
        $id = $this->owedRelease(Category::TV_HD);

        $this->assertSame(0, $this->recategorizer(Category::TV_HD)->refileCategory(Category::TV_HD));

        $this->assertMoved($id, Category::TV_HD, categorized: 0, links: $this->links(), preview: -2);
        $this->assertSame([], $this->synchronized);
    }

    public function test_refinement_moves_without_a_synchronized_preview_restore(): void
    {
        $id = $this->owedRelease(Category::XXX_OTHER);
        $this->hevcVideo($id);

        $this->assertSame(Category::XXX_X264, (new MediaInfoRefinementService)->refine($id)?->categoryId);

        $this->assertMoved($id, Category::XXX_X264, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_refinement_writes_only_over_the_category_it_read(): void
    {
        $id = $this->owedRelease(Category::XXX_OTHER);
        $this->hevcVideo($id);
        DB::listen(static function (QueryExecuted $query) use ($id): void {
            static $moved = false;
            if (! $moved && str_contains($query->sql, 'from "video_data"')) {
                $moved = true;
                DB::table('releases')->where('id', $id)->update(['categories_id' => Category::XXX_UHD]);
            }
        });

        $this->assertNull((new MediaInfoRefinementService)->refine($id));

        $this->assertMoved($id, Category::XXX_UHD, categorized: 0, links: $this->links(), preview: -2);
        $this->assertSame([], $this->synchronized);
    }

    public function test_refinement_declines_a_category_outside_the_forced_root(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => Category::XXX_ROOT]);
        $id = $this->owedRelease(Category::XXX_OTHER);
        DB::table('audio_data')->insert(['id' => 1, 'releases_id' => $id, 'audioid' => 1, 'audioformat' => 'MPEG Audio']);

        $this->assertNull((new MediaInfoRefinementService)->refine($id));

        $this->assertMoved($id, Category::XXX_OTHER, categorized: 0, links: $this->links(), preview: -2);
    }

    /** Decision 5 (#1120): the bulk move clears only the links each release's old category owned. */
    public function test_the_admin_bulk_edit_moves_every_release_in_one_update_and_clears_the_links_it_leaves(): void
    {
        $first = $this->owedRelease(Category::TV_HD);
        $second = $this->owedRelease(Category::MOVIE_HD, [], 2);
        $this->hevcVideo($first);
        $statements = 0;
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements += str_starts_with(strtolower($query->sql), 'update "releases" set') && str_contains($query->sql, '"categories_id" =') ? 1 : 0;
        });

        $this->assertSame(2, (new ReleaseManagementService)->bulkUpdateCategory([sprintf('%040d', $first), sprintf('%040d', $second)], Category::MOVIE_OTHER));

        $this->assertSame(1, $statements);
        $this->assertMoved($first, Category::MOVIE_OTHER, categorized: 1, links: $this->links(['videos_id' => 0, 'tv_episodes_id' => 0]), preview: -1);
        $this->assertMoved($second, Category::MOVIE_OTHER, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$first, $second, $first, $second], $this->synchronized);
    }

    public function test_a_junk_book_moves_to_books_unknown_without_marking_it_categorized(): void
    {
        $id = $this->owedRelease(Category::BOOKS_EBOOK, ['searchname' => 'Foxit PDF Editor Pro 14.0.4.33508 Multilingual x64']);

        $this->assertFalse($this->bookService()->parseTitle('Foxit PDF Editor Pro 14.0.4.33508 Multilingual x64', $id, 'ebook'));

        $this->assertMoved($id, Category::BOOKS_UNKNOWN, categorized: 0, links: $this->links(), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    public function test_a_magazine_moves_to_books_magazines_without_marking_it_categorized(): void
    {
        $id = $this->owedRelease(Category::BOOKS_EBOOK, ['searchname' => 'History of War - Issue 158, 2026']);

        $this->assertFalse($this->bookService()->parseTitle('History of War - Issue 158, 2026', $id, 'ebook'));

        $this->assertMoved($id, Category::BOOKS_MAGAZINES, categorized: 0, links: $this->links(), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    /** Decision 5 (#1120): leaving Movies clears the movie links. */
    public function test_a_movie_handed_to_tv_moves_to_tv_other_and_is_refined(): void
    {
        $id = $this->owedRelease(Category::MOVIE_OTHER);
        $this->hevcVideo($id);

        $this->assertFalse($this->handoffToTv($id, MovieLookupOutcome::NotFound, ['video_id' => 41]));

        $this->assertMoved($id, Category::TV_X265, categorized: 1, links: $this->links(['movieinfo_id' => null, 'imdbid' => null]), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    /** Decision 5 (#1120): leaving Movies clears the movie links. */
    public function test_a_movie_handed_to_tv_restores_no_previews_of_its_own(): void
    {
        $id = $this->owedRelease(Category::MOVIE_OTHER);

        $this->assertTrue($this->handoffToTv($id, MovieLookupOutcome::Series, ['video_id' => 41]));

        $this->assertMoved($id, Category::TV_OTHER, categorized: 0, links: $this->links(['movieinfo_id' => null, 'imdbid' => null]), preview: -2);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_a_series_in_a_movie_forced_group_stays_in_movies_without_its_imdb_id(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['forced_root_categories_id' => Category::MOVIE_ROOT]);
        $id = $this->owedRelease(Category::MOVIE_OTHER);

        $this->assertTrue($this->handoffToTv($id, MovieLookupOutcome::Series, ['video_id' => 0]));

        $this->assertMoved($id, Category::MOVIE_OTHER, categorized: 0, links: $this->links(['imdbid' => '']), preview: -2);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_the_legacy_categorizer_files_uncategorized_misc_releases(): void
    {
        $id = $this->owedRelease(Category::OTHER_MISC, ['searchname' => 'Some.Movie.2024.1080p.BluRay.x264-GROUP']);
        $service = (new ReflectionClass(ReleaseProcessingService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ReleaseProcessingService::class, 'echoCLI'))->setValue($service, false);

        $this->assertSame(1, $service->categorizeRelease('searchname', null));

        $this->assertMoved($id, Category::MOVIE_HD, categorized: 1, links: $this->links(), preview: -1);
        $this->assertSame([$id, $id], $this->synchronized);
    }

    /** @param array<string, mixed> $overrides */
    private function owedRelease(int $category, array $overrides = [], int $id = 1): int
    {
        return $this->linkedRelease($id, [
            'categories_id' => $category,
            'haspreview' => PreviewGenerationPolicy::HASPREVIEW_SKIPPED_BY_POLICY,
            ...$overrides,
        ]);
    }

    private function hevcVideo(int $id): void
    {
        DB::table('video_data')->insert(['releases_id' => $id, 'videoformat' => self::HEVC, 'videowidth' => 1920, 'videoheight' => 1080]);
    }

    private function listener(?int $pipelineCategory): RecategorizeReleaseAfterNameFix
    {
        $categorization = Mockery::mock(CategorizationService::class);
        if ($pipelineCategory === null) {
            $categorization->shouldNotReceive('determineCategory');
        } else {
            $categorization->shouldReceive('determineCategory')->once()->andReturn(['categories_id' => $pipelineCategory]);
        }

        return new RecategorizeReleaseAfterNameFix($categorization, new PreviewGenerationPolicy, new MediaInfoRefinementService);
    }

    private function event(int $id, ?int $override = null): ReleaseNameFixed
    {
        return new ReleaseNameFixed($id, 'old', 'Some.Movie.2024.1080p.BluRay.x264-GROUP', Category::OTHER_HASHED, 1, 'poster', $override);
    }

    private function recategorizer(int $pipelineCategory): ReleaseRecategorizer
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->andReturn(['categories_id' => $pipelineCategory]);
        $recategorizer = new ReleaseRecategorizer;
        (new ReflectionProperty(ReleaseRecategorizer::class, 'categorization'))->setValue($recategorizer, $categorization);

        return $recategorizer;
    }

    private function bookService(): BookService
    {
        $service = (new ReflectionClass(BookService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BookService::class, 'obfuscatedSubjectExtractor'))->setValue($service, new ObfuscatedSubjectExtractor);
        (new ReflectionProperty(BookService::class, 'echooutput'))->setValue($service, false);
        $updates = Mockery::mock(ReleaseUpdateService::class);
        $updates->shouldReceive('renameFromBookMetadata');
        (new ReflectionProperty(BookService::class, 'releaseUpdateService'))->setValue($service, $updates);

        return $service;
    }

    /** @param array<string, mixed> $tvResult */
    private function handoffToTv(int $id, MovieLookupOutcome $outcome, array $tvResult): bool
    {
        $pipeline = Mockery::mock(TvProcessingPipeline::class);
        $pipeline->shouldReceive('processRelease')->once()->andReturn($tvResult);
        $this->app->instance(TvProcessingPipeline::class, $pipeline);
        $service = (new ReflectionClass(MovieService::class))->newInstanceWithoutConstructor();

        return (bool) (new ReflectionMethod(MovieService::class, 'handoffToTv'))->invoke($service, $id, $outcome);
    }

    /** @param array<string, mixed> $links */
    private function assertMoved(int $id, int $category, int $categorized, array $links, int $preview): void
    {
        $row = $this->releaseRow($id);
        $this->assertSame([$category, $categorized, $preview], [(int) $row['categories_id'], (int) $row['iscategorized'], (int) $row['haspreview']]);
        $this->assertSame($links, $this->linksOf($id));
    }
}
