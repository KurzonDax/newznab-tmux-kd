<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use Database\Seeders\SettingsTableSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The stored `nzbsplitlevel` has to mean one thing to both halves of NZB storage:
 * an explicit 0 is flat storage, and a blank or missing row is "unset" rather than 0.
 */
final class NzbSplitLevelResolutionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbDirectory;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '4'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbDirectory = $this->makeTempDirectory('nzb-split-level').DIRECTORY_SEPARATOR;
        config(['nntmux_settings.path_to_nzbs' => $this->nzbDirectory]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_explicit_zero_writes_and_reads_flat(): void
    {
        $this->storeSplitLevel('0');

        $service = app(NzbService::class);
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';

        $this->assertSame(0, $service->getNzbSplitLevel());

        $writtenPath = $service->getNzbPath($guid, 0, true);
        file_put_contents($writtenPath, 'test');

        $this->assertSame($this->nzbDirectory.$guid.'.nzb.gz', $writtenPath);
        $this->assertSame($writtenPath, $service->nzbPath($guid));
    }

    public function test_the_coded_default_matches_the_value_a_fresh_install_is_seeded_with(): void
    {
        (new SettingsTableSeeder)->run();

        $this->assertSame(
            (string) NzbService::DEFAULT_SPLIT_LEVEL,
            (string) DB::table('settings')->where('name', 'nzbsplitlevel')->value('value'),
            'An unset row must resolve to the depth a fresh install would have used.'
        );
    }

    public function test_a_blank_row_writes_and_reads_at_the_default_depth(): void
    {
        $this->storeSplitLevel('');

        $this->assertUnsetRowUsesTheDefaultDepth();
    }

    public function test_a_cleared_row_stored_as_null_writes_and_reads_at_the_default_depth(): void
    {
        $this->storeSplitLevel(null);

        $this->assertUnsetRowUsesTheDefaultDepth();
    }

    public function test_a_missing_row_writes_and_reads_at_the_default_depth(): void
    {
        DB::table('settings')->where('name', 'nzbsplitlevel')->delete();

        $this->assertUnsetRowUsesTheDefaultDepth();
    }

    /**
     * An "unset" split level must resolve to the coded default on both halves of storage:
     * the write fans out four levels deep, and the read finds it there.
     */
    private function assertUnsetRowUsesTheDefaultDepth(): void
    {
        $service = app(NzbService::class);
        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';

        $this->assertSame(NzbService::DEFAULT_SPLIT_LEVEL, $service->getNzbSplitLevel());

        $writtenPath = $service->getNzbPath($guid, 0, true);
        file_put_contents($writtenPath, 'test');

        $this->assertSame($this->nzbDirectory.'4/a/a/b/'.$guid.'.nzb.gz', $writtenPath);
        $this->assertSame($writtenPath, $service->nzbPath($guid));
    }

    public function test_a_stored_level_still_reads_a_file_written_before_the_level_changed(): void
    {
        $this->storeSplitLevel('1');

        $guid = '4aabfe07-daff-4d28-9d1d-d2a4ab7b6511';
        $writtenPath = app(NzbService::class)->getNzbPath($guid, 0, true);
        file_put_contents($writtenPath, 'test');
        $this->assertSame($this->nzbDirectory.'4/'.$guid.'.nzb.gz', $writtenPath);

        $this->storeSplitLevel('3');

        $this->assertSame($writtenPath, app(NzbService::class)->nzbPath($guid));
    }

    private function storeSplitLevel(?string $value): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'nzbsplitlevel'], ['value' => $value]);
    }
}
