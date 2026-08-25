<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class NntmuxResetTruncateTest extends TestCase
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
            'nzbsplitlevel' => '1',
            'releaseprocessingtimeout' => '120',
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

    #[Test]
    public function reset_truncate_cleans_unclaimed_nzbless_releases_through_the_canonical_path(): void
    {
        $nzbRoot = $this->makeTempDirectory('reset-truncate-nzb').'/';
        $coversRoot = $this->makeTempDirectory('reset-truncate-covers');
        config([
            'nntmux_settings.path_to_nzbs' => $nzbRoot,
            'nntmux_settings.covers_path' => $coversRoot,
        ]);

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'first_record' => 100,
            'last_record' => 200,
        ]);
        DB::table('releases')->insert([
            $this->releaseRow(1, nzbStatus: 0),
            $this->releaseRow(2, nzbStatus: 0, additionalClaimedAt: now()),
            $this->releaseRow(3, nzbStatus: 1),
        ]);
        foreach (['parts', 'missed_parts', 'binaries', 'collections'] as $table) {
            DB::table($table)->insert(['id' => 1]);
        }

        $deletedArtifacts = $this->createArtifacts(1);
        $protectedArtifacts = $this->createArtifacts(2);
        Search::shouldReceive('deleteReleases')->once()->with([1]);

        $this->artisan('nntmux:reset-truncate')->assertSuccessful();

        $this->assertSame([2, 3], DB::table('releases')->orderBy('id')->pluck('id')->map(intval(...))->all());
        $this->assertSame(0, (int) DB::table('usenet_groups')->value('first_record'));
        $this->assertSame(0, (int) DB::table('usenet_groups')->value('last_record'));
        foreach (['parts', 'missed_parts', 'binaries', 'collections'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
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
        int $nzbStatus,
        mixed $additionalClaimedAt = null,
    ): array {
        return [
            'id' => $id,
            'guid' => str_repeat((string) $id, 40),
            'nzbstatus' => $nzbStatus,
            'additional_pp_claimed_at' => $additionalClaimedAt,
            'recovery_claimed_at' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function createArtifacts(int $releaseId): array
    {
        $guid = str_repeat((string) $releaseId, 40);
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

    private function createSchema(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('first_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40);
            $table->integer('nzbstatus');
            $table->dateTime('additional_pp_claimed_at')->nullable();
            $table->dateTime('recovery_claimed_at')->nullable();
        });
        foreach (['parts', 'missed_parts', 'binaries', 'collections'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->increments('id');
            });
        }
    }
}
