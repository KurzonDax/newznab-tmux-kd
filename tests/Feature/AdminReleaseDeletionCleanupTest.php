<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AdminReleaseDeletionCleanupTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot;

    private string $coversRoot;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'nzbsplitlevel' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->withoutMiddleware();
        $this->createSchema();

        $this->nzbRoot = $this->makeTempDirectory('admin-release-deletion-nzb').'/';
        $this->coversRoot = $this->makeTempDirectory('admin-release-deletion-covers');
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbRoot,
            'nntmux_settings.covers_path' => $this->coversRoot,
        ]);

        Auth::shouldReceive('id')->andReturn(99);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function deleting_one_reported_release_removes_its_nzb_artifacts_and_search_document(): void
    {
        $artifacts = $this->createReportedRelease(1, str_repeat('a', 40));
        Search::shouldReceive('deleteRelease')->once()->with(1);

        $this->post(route('admin.release-reports.delete-release', 1))
            ->assertRedirect();

        $this->assertDatabaseMissing('releases', ['id' => 1]);
        $this->assertDatabaseHas('release_reports', ['id' => 1, 'status' => 'resolved']);
        $this->assertArtifactsDeleted($artifacts);
    }

    #[Test]
    public function bulk_deleting_reported_releases_removes_their_nzbs_artifacts_and_search_documents(): void
    {
        $firstArtifacts = $this->createReportedRelease(1, str_repeat('a', 40));
        $secondArtifacts = $this->createReportedRelease(2, str_repeat('b', 40));
        Search::shouldReceive('deleteReleases')
            ->once()
            ->withArgs(static function (array $ids): bool {
                sort($ids);

                return $ids === [1, 2];
            });

        $this->post(route('admin.release-reports.bulk'), [
            'action' => 'delete',
            'report_ids' => [1, 2],
        ])->assertRedirect();

        $this->assertDatabaseMissing('releases', ['id' => 1]);
        $this->assertDatabaseMissing('releases', ['id' => 2]);
        $this->assertDatabaseHas('release_reports', ['id' => 1, 'status' => 'resolved']);
        $this->assertDatabaseHas('release_reports', ['id' => 2, 'status' => 'resolved']);
        $this->assertArtifactsDeleted($firstArtifacts);
        $this->assertArtifactsDeleted($secondArtifacts);
    }

    /**
     * @return list<string>
     */
    private function createReportedRelease(int $id, string $guid): array
    {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'searchname' => 'Reported release '.$id,
        ]);
        DB::table('release_reports')->insert([
            'id' => $id,
            'releases_id' => $id,
            'users_id' => 10,
            'reason' => 'spam',
            'status' => 'pending',
        ]);

        $nzb = app(NzbService::class);
        $imageService = new ReleaseImageService;
        $artifacts = [
            $nzb->getNzbPath($guid, 1, true),
            $imageService->vidSavePath.$guid.'.ogv',
            $imageService->imgSavePath.$guid.'_thumb.webp',
            $imageService->audSavePath.$guid.'.mp3',
            $imageService->audSavePath.$guid.'_spectrum.png',
        ];

        foreach ($artifacts as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'delete me');
        }

        return $artifacts;
    }

    /**
     * @param  list<string>  $artifacts
     */
    private function assertArtifactsDeleted(array $artifacts): void
    {
        foreach ($artifacts as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    private function createSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40);
            $table->string('searchname');
        });
        Schema::create('release_reports', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('users_id');
            $table->string('reason');
            $table->text('description')->nullable();
            $table->string('status');
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }
}
