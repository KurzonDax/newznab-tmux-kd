<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class NntmuxRemoveBadClaimsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot;

    private string $coversRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        $this->nzbRoot = $this->makeTempDirectory('remove-bad-nzb').'/';
        $this->coversRoot = $this->makeTempDirectory('remove-bad-covers');
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

    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'nzbsplitlevel' => '1',
            'releaseprocessingtimeout' => '120',
        ];
    }

    #[Test]
    public function remove_bad_skips_live_claims_and_deletes_stale_or_unclaimed_rows(): void
    {
        $live = now();
        $stale = now()->subHour();

        DB::table('releases')->insert([
            $this->releaseRow(1, additionalClaimedAt: $live),
            $this->releaseRow(2, recoveryClaimedAt: $live),
            $this->releaseRow(3, additionalClaimedAt: $stale),
            $this->releaseRow(4, recoveryClaimedAt: $stale),
            $this->releaseRow(5),
        ]);

        $protectedArtifacts = $this->createArtifacts(1);
        $deletedArtifacts = [
            ...$this->createArtifacts(3),
            ...$this->createArtifacts(4),
            ...$this->createArtifacts(5),
        ];
        foreach ([3, 4, 5] as $releaseId) {
            Search::shouldReceive('deleteReleases')->once()->with([$releaseId]);
        }

        $this->artisan('nntmux:remove-bad')->assertSuccessful();

        $this->assertSame([1, 2], DB::table('releases')->orderBy('id')->pluck('id')->map(intval(...))->all());
        foreach ($deletedArtifacts as $path) {
            $this->assertFileDoesNotExist($path);
        }
        foreach ($protectedArtifacts as $path) {
            $this->assertFileExists($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(
        int $id,
        mixed $additionalClaimedAt = null,
        mixed $recoveryClaimedAt = null,
    ): array {
        return [
            'id' => $id,
            'guid' => sprintf('%032x', $id),
            'passwordstatus' => -2,
            'additional_pp_claimed_at' => $additionalClaimedAt,
            'recovery_claimed_at' => $recoveryClaimedAt,
        ];
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS release_files');
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            passwordstatus INTEGER NOT NULL DEFAULT -1,
            additional_pp_claimed_at DATETIME NULL,
            recovery_claimed_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE release_files (
            releases_id INTEGER NOT NULL,
            passworded INTEGER NOT NULL DEFAULT 0
        )');
    }

    /**
     * @return list<string>
     */
    private function createArtifacts(int $releaseId): array
    {
        $guid = sprintf('%032x', $releaseId);
        $nzb = app(NzbService::class);
        $images = new ReleaseImageService;
        $paths = [
            $nzb->getNzbPath($guid, 1, true),
            $images->vidSavePath.$guid.'.ogv',
            $images->audSavePath.$guid.'.mp3',
            $images->audSavePath.$guid.'_spectrum.png',
        ];

        foreach ($paths as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'delete me');
        }

        return $paths;
    }
}
