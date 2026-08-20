<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Measuring `completion` for releases stored before it was recorded.
 *
 * The arithmetic has to match creation time exactly, or a backfilled value would mean something
 * different from one next to it and the sweep threshold would compare apples to oranges.
 */
class BackfillReleaseCompletionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private string $nzbRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        $this->nzbRoot = $this->makeTempDirectory('nntmux-backfill-nzbs');
        config(['nntmux_settings.path_to_nzbs' => $this->nzbRoot]);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1'];
    }

    #[Test]
    public function it_measures_unrecorded_releases_from_their_stored_nzbs(): void
    {
        $this->releaseWithNzb(1, present: 5, declared: 10);
        $this->releaseWithNzb(2, present: 10, declared: 10);

        $this->artisan('releases:backfill-completion')->assertSuccessful();

        $this->assertSame(50.0, $this->completionOf(1));
        $this->assertSame(100.0, $this->completionOf(2));
    }

    #[Test]
    public function a_release_whose_subjects_declare_no_totals_keeps_the_never_measured_sentinel(): void
    {
        // No denominator means nothing to measure against, so `0` keeps meaning "unknown" and
        // the release stays exempt from the completion sweep rather than looking like 0%.
        $this->releaseWithNzb(1, present: 4, declared: 0);

        $this->artisan('releases:backfill-completion')->assertSuccessful();

        $this->assertSame(0.0, $this->completionOf(1));
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->releaseWithNzb(1, present: 5, declared: 10);

        $this->artisan('releases:backfill-completion', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->completionOf(1));
    }

    #[Test]
    public function the_dry_run_reports_the_completion_bands(): void
    {
        $this->releaseWithNzb(1, present: 1, declared: 100);   // 1%    -> 0-<10 band
        $this->releaseWithNzb(2, present: 60, declared: 100);  // 60%   -> 50-<75 band
        $this->releaseWithNzb(3, present: 99, declared: 100);  // 99%   -> 99-100 band

        $this->artisan('releases:backfill-completion', ['--dry-run' => true])
            ->expectsOutputToContain('Completion band')
            ->expectsOutputToContain('Examined 3 release(s): 3 measured')
            ->assertSuccessful();
    }

    #[Test]
    public function releases_that_already_have_a_measurement_are_left_alone(): void
    {
        $this->releaseWithNzb(1, present: 5, declared: 10);
        DB::table('releases')->where('id', 1)->update(['completion' => 42.5]);

        $this->artisan('releases:backfill-completion')->assertSuccessful();

        $this->assertSame(42.5, $this->completionOf(1));
    }

    #[Test]
    public function a_release_with_no_nzb_on_disk_is_counted_and_skipped(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'guid' => sprintf('%032x', 1),
            'nzbstatus' => 1,
            'completion' => 0,
        ]);

        $this->artisan('releases:backfill-completion')
            ->expectsOutputToContain('1 with no NZB on disk')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->completionOf(1));
    }

    #[Test]
    public function the_limit_stops_the_walk_early(): void
    {
        $this->releaseWithNzb(1, present: 5, declared: 10);
        $this->releaseWithNzb(2, present: 5, declared: 10);

        $this->artisan('releases:backfill-completion', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(50.0, $this->completionOf(1));
        $this->assertSame(0.0, $this->completionOf(2));
    }

    private function releaseWithNzb(int $id, int $present, int $declared): void
    {
        $guid = sprintf('%032x', $id);

        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'nzbstatus' => 1,
            'completion' => 0,
        ]);

        $subject = $declared > 0
            ? 'Example.part01.rar yEnc (1/'.$declared.')'
            : 'Example.part01.rar yEnc';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n"
            .'  <file poster="p@example.org" date="1700000000" subject="'.$subject.'">'."\n"
            .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n";

        for ($number = 1; $number <= $present; $number++) {
            $xml .= '      <segment bytes="900" number="'.$number.'">part'.$number.'@host</segment>'."\n";
        }

        $xml .= "    </segments>\n  </file>\n".'</nzb>'."\n";

        file_put_contents(app(NzbService::class)->getNzbPath($guid, 0, true), gzencode($xml));
    }

    private function completionOf(int $id): float
    {
        return (float) DB::table('releases')->where('id', $id)->value('completion');
    }

    private function createSchema(): void
    {
        DB::statement('DROP TABLE IF EXISTS releases');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL
        )');
    }
}
