<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/** releases:fix-nzbsplit and books:requeue-failed rename through the name writer (#1120, decision 3). */
class NameRepairCommandsTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    private const string WRAPPED = 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065" yEnc(1';

    private const string UNWRAPPED = 'Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)';

    private const string BOOK = 'N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"';

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createNameWriteTables();
        config(['nntmux.categorization.log' => false]);
        Search::shouldReceive('updateRelease')->andReturnTrue();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_fix_nzbsplit_keeps_the_trust_of_an_already_trusted_renamed_release(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $id = $this->linkedRelease(1, ['name' => self::WRAPPED, 'searchname' => self::WRAPPED, 'isrenamed' => 1,
            'is_trusted_name' => 1, 'predb_id' => 9, 'name_source' => 'PAR2']);

        $this->artisan('releases:fix-nzbsplit', ['--include-renamed' => true])
            ->expectsOutputToContain('Updated 1 NZBSPLIT-wrapped releases.')
            ->assertSuccessful();

        $row = $this->releaseRow($id);
        $this->assertSame([self::UNWRAPPED, self::UNWRAPPED, 'NZBSPLIT repair', 1, 9], [$row['name'], $row['searchname'],
            $row['name_source'], (int) $row['is_trusted_name'], (int) $row['predb_id']]);
        $this->assertNull($row['bookinfo_id']);
        $this->assertNull($row['movieinfo_id']);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->releaseId === $id && $event->categoryOverride === null);
    }

    public function test_fix_nzbsplit_files_the_renamed_release_through_the_rename_listener(): void
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->once()->andReturn(['categories_id' => Category::TV_HD]);
        $this->app->instance(CategorizationService::class, $categorization);
        $id = $this->linkedRelease(1, ['name' => self::WRAPPED, 'searchname' => self::WRAPPED]);

        $this->artisan('releases:fix-nzbsplit')->assertSuccessful();

        $this->assertSame(Category::TV_HD, (int) $this->releaseRow($id)['categories_id']);
    }

    public function test_fix_nzbsplit_reports_a_rename_the_recovery_identity_refuses_and_does_not_count_it(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $id = $this->linkedRelease(1, ['name' => self::WRAPPED, 'searchname' => self::WRAPPED]);
        $this->unpublishedRecovery($id);

        $this->artisan('releases:fix-nzbsplit')
            ->expectsOutputToContain('#1 not renamed: recovery_identity')
            ->expectsOutputToContain('Updated 0 NZBSPLIT-wrapped releases.')
            ->assertSuccessful();

        $this->assertSame(self::WRAPPED, $this->releaseRow($id)['searchname']);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_a_normalized_book_stays_in_its_books_category_for_the_book_lookup(): void
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldNotReceive('determineCategory');
        $this->app->instance(CategorizationService::class, $categorization);
        $id = $this->linkedRelease(1, ['name' => self::BOOK, 'searchname' => self::BOOK, 'categories_id' => Category::BOOKS_EBOOK,
            'iscategorized' => 1]);

        $this->artisan('books:requeue-failed', ['--normalize-obfuscated-all' => true])
            ->expectsOutputToContain('Normalized 1 searchname value(s).')
            ->assertSuccessful();

        $row = $this->releaseRow($id);
        $this->assertSame(['History of War - Issue 158, 2026', 'Book subject repair', Category::BOOKS_EBOOK, null],
            [$row['searchname'], $row['name_source'], (int) $row['categories_id'], $row['bookinfo_id']]);
    }

    public function test_a_refused_book_rename_still_requeues_the_failed_lookup(): void
    {
        Event::fake([ReleaseNameFixed::class]);
        $id = $this->linkedRelease(1, ['name' => self::BOOK, 'searchname' => self::BOOK, 'categories_id' => Category::BOOKS_EBOOK,
            'bookinfo_id' => -2]);
        $this->unpublishedRecovery($id);

        $this->artisan('books:requeue-failed')
            ->expectsOutputToContain('#1 not renamed: recovery_identity')
            ->expectsOutputToContain('Requeued 1 failed book release(s). Normalized 0 searchname value(s). Skipped: 0.')
            ->assertSuccessful();

        $row = $this->releaseRow($id);
        $this->assertSame([self::BOOK, null], [$row['searchname'], $row['bookinfo_id']]);
    }

    /** A recovery publication still being prepared: no name may be written for its release. */
    private function unpublishedRecovery(int $releaseId): void
    {
        ProductionTables::fromAuthority()->create('obfuscation_recovery_publications', [
            'id', 'releases_id', 'guid', 'state', 'initialization_state', 'sealed_plan', 'multi_media_inventory', 'deleted_at',
        ]);
        DB::table('obfuscation_recovery_publications')->insert([
            'id' => 1, 'releases_id' => $releaseId, 'guid' => sprintf('%040d', $releaseId), 'state' => 'preparing',
            'initialization_state' => 'pending', 'sealed_plan' => '{}', 'multi_media_inventory' => 0,
        ]);
    }
}
