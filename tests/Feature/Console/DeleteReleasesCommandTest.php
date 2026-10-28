<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class DeleteReleasesCommandTest extends TestCase
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
        $this->createSchema();
        $this->nzbRoot = $this->makeTempDirectory('delete-releases-nzb').'/';
        $this->coversRoot = $this->makeTempDirectory('delete-releases-covers');
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbRoot,
            'nntmux_settings.covers_path' => $this->coversRoot,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    #[Test]
    public function deleting_a_release_removes_its_files_tombstones_its_publication_and_keeps_download_history(): void
    {
        $guid = str_repeat('a', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'Doomed.Release', 'name' => 'doomed']);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'doomed.mkv']);
        DB::table('user_downloads')->insert(['users_id' => 7, 'releases_id' => 1, 'hosthash' => '', 'timestamp' => now()]);
        $this->publication(1, $guid);

        $images = new ReleaseImageService;
        $artifacts = [
            app(NzbService::class)->getNzbPath($guid, 1, true),
            $images->imgSavePath.$guid.'_thumb.webp',
            $images->jpgSavePath.$guid.'_thumb.webp',
        ];
        foreach ($artifacts as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'delete me');
        }
        Search::shouldReceive('deleteReleases')->once()->with([1]);

        $this->artisan('nntmux:delete-releases', ['--guid' => $guid, '--force' => true])
            ->expectsOutput('Deleting: Doomed.Release')
            ->expectsOutput('Successfully deleted 1 release(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('releases', ['id' => 1]);
        foreach ($artifacts as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertDatabaseMissing('release_files', ['releases_id' => 1]);
        $this->assertDatabaseHas('user_downloads', ['users_id' => 7, 'releases_id' => 1]);
        $publication = DB::table('obfuscation_recovery_publications')->where('id', 1)->first();
        $this->assertNotNull($publication->deleted_at);
        $this->assertSame('tombstoned', $publication->state);
    }

    #[Test]
    public function a_failed_chunk_is_reported_and_the_next_chunk_still_runs(): void
    {
        DB::table('releases')->insert(array_map(static fn (int $id): array => [
            'id' => $id, 'guid' => str_pad((string) $id, 40, '0', STR_PAD_LEFT), 'searchname' => 'batch-'.$id, 'name' => 'batch-'.$id,
        ], range(1, 101)));

        $management = Mockery::mock(ReleaseManagementService::class);
        $management->shouldReceive('deleteBatch')->once()->ordered()
            ->withArgs(static fn (iterable $releases): bool => count($releases) === 100)
            ->andThrow(new \RuntimeException('Injected chunk failure'));
        $management->shouldReceive('deleteBatch')->once()->ordered()
            ->withArgs(static fn (iterable $releases): bool => count($releases) === 1)
            ->andReturn(1);
        $this->app->instance(ReleaseManagementService::class, $management);

        $this->artisan('nntmux:delete-releases', ['--name-like' => 'batch', '--force' => true])
            ->expectsOutput('Failed to delete 100 of 100 release(s) in this chunk; see the log.')
            ->expectsOutput('Deleted 1/101 releases...')
            ->expectsOutput('Successfully deleted 1 release(s).')
            ->expectsOutput('100 release(s) were not deleted; re-run the same criteria to finish.')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_dry_run_lists_the_matching_releases_and_deletes_nothing(): void
    {
        $guid = str_repeat('b', 40);
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'searchname' => 'Kept.Release', 'name' => 'kept']);
        Search::shouldReceive('deleteReleases')->never();

        $this->artisan('nntmux:delete-releases', ['--guid' => $guid, '--dry-run' => true])
            ->expectsOutput('Found 1 release(s) that would be deleted:')
            ->expectsOutput(sprintf('  [%s] Kept.Release', $guid))
            ->expectsOutput('Total: 1 release(s) would be deleted')
            ->assertExitCode(0);

        $this->assertDatabaseHas('releases', ['id' => 1]);
    }

    private function publication(int $releaseId, string $guid): void
    {
        DB::table('obfuscation_recovery_publications')->insert([
            'id' => 1, 'identity' => hash('sha256', 'identity'), 'index_identity' => hash('sha256', 'index'),
            'index_message_id' => '<index@example.test>', 'set_id' => str_repeat('a', 32),
            'plan_digest' => str_repeat('b', 64), 'collection_projection' => sha1('collection', true),
            'releases_id' => $releaseId, 'guid' => $guid, 'profile' => 'nyuu-media-v1',
            'group_name' => 'alt.binaries.example', 'source_epoch' => 'epoch', 'state' => 'registered',
            'ordering_mode' => 'media_inventory', 'inventory_scope' => 'protected_files',
            'protected_files' => 1, 'planned_files' => 1, 'planned_parts' => 1,
            'sealed_plan' => '{}', 'manifest_digest' => str_repeat('c', 64),
        ]);
    }

    private function createSchema(): void
    {
        $tables = ProductionTables::fromAuthority();
        $tables->create('releases', ['id', 'guid', 'searchname', 'name']);
        // ProductionTables emits no foreign keys; production cascades release_files on release delete.
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->primary(['releases_id', 'name']);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        $tables->create('user_downloads');
        $tables->create('obfuscation_recovery_publications');
    }
}
