<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class NntmuxRemoveBadClaimsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createSchema();
        config(['nntmux_settings.covers_path' => $this->makeTempDirectory('remove-bad-covers')]);
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

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('deleteNzb')->times(6)->andReturnTrue();
        app()->instance(NzbService::class, $nzb);
        Search::shouldReceive('deleteRelease')->times(6);

        $this->artisan('nntmux:remove-bad')->assertSuccessful();

        $this->assertSame([1, 2], DB::table('releases')->orderBy('id')->pluck('id')->map(intval(...))->all());
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
}
