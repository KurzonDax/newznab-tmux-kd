<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategoryChange;
use App\Services\Categorization\CategoryChangeKind;
use App\Services\NameFixing\NameCandidate;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\NameWriteRefusal;
use App\Services\NameFixing\ReleaseNameWriter;
use App\Services\ObfuscationRecovery\RecoveryNameEvidence;
use App\Services\ObfuscationRecovery\RecoveryNameScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\TestCase;

class ReleaseNameWriterTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

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
        Event::fake([ReleaseNameFixed::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_rename_the_recovery_identity_refuses_is_reported_and_not_written(): void
    {
        $id = $this->linkedRelease(1);
        $evidence = new RecoveryNameEvidence(1, str_repeat('a', 64), RecoveryNameScope::SingleFile, ['file-1']);

        $outcome = $this->writer()->rename(
            Release::query()->findOrFail($id),
            new NameCandidate($id, 'Some.Movie.2024.1080p.BluRay.x264-GROUP', NameSource::AudioTags, 'label', recoveryEvidence: $evidence),
        );

        $this->assertTrue($outcome->decision->accepted());
        $this->assertFalse($outcome->written);
        $this->assertSame(NameWriteRefusal::RecoveryIdentity, $outcome->refusal);
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $this->releaseRow($id)['searchname']);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_a_dry_run_decides_without_writing(): void
    {
        $id = $this->linkedRelease(1);

        $outcome = $this->writer()->rename(
            Release::query()->findOrFail($id),
            new NameCandidate($id, 'Some.Movie.2024.1080p.BluRay.x264-GROUP', NameSource::FileName, 'label'),
            write: false,
        );

        $this->assertTrue($outcome->decision->accepted());
        $this->assertFalse($outcome->written);
        $this->assertNull($outcome->refusal);
        $this->assertSame([], $this->synchronized);
    }

    public function test_a_category_move_over_a_stale_category_changes_nothing(): void
    {
        $id = $this->linkedRelease(1, ['categories_id' => Category::TV_HD]);
        DB::table('releases')->where('id', $id)->update(['categories_id' => Category::TV_ANIME]);

        $this->assertFalse((new CategoryChange)->apply($id, Category::TV_HD, Category::TV_OTHER, CategoryChangeKind::Recategorize));

        $this->assertSame(Category::TV_ANIME, (int) $this->releaseRow($id)['categories_id']);
        $this->assertSame($this->links(), $this->linksOf($id));
    }

    private function writer(): ReleaseNameWriter
    {
        return new ReleaseNameWriter(new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId): void {
                $this->synchronized[] = $releaseId;
            },
        ));
    }
}
