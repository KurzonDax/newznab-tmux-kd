<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\PhantomTrailingSets;
use Tests\Support\ProductionTables;
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

    #[Test]
    public function it_restates_understated_single_segment_releases(): void
    {
        // 220 of 240 files, each a lone segment whose parens repeat the collection-wide total.
        // Summing that total once per file stored 0.42% for a release that is ~92% there.
        $this->obfuscatedReleaseWithNzb(1, files: 220, declaredTotal: 240, completion: 0.42);

        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertEqualsWithDelta(91.67, $this->completionOf(1), 0.01);
    }

    #[Test]
    public function restating_the_same_release_twice_lands_on_the_same_value(): void
    {
        $this->obfuscatedReleaseWithNzb(1, files: 220, declaredTotal: 240, completion: 0.42);

        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();
        $first = $this->completionOf(1);
        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertSame($first, $this->completionOf(1));
    }

    #[Test]
    public function the_understated_pass_leaves_rows_above_the_ceiling_alone(): void
    {
        $this->releaseWithNzb(1, present: 5, declared: 10);
        DB::table('releases')->where('id', 1)->update(['completion' => 60.0]);

        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertSame(60.0, $this->completionOf(1));
    }

    #[Test]
    public function the_understated_pass_leaves_the_never_measured_sentinel_alone(): void
    {
        // True zeros are the default pass's population, and a `0` there means "unknown", not 0%.
        $this->releaseWithNzb(1, present: 5, declared: 10);

        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertSame(0.0, $this->completionOf(1));
    }

    #[Test]
    public function an_understated_row_whose_nzb_declares_no_totals_keeps_its_stored_value(): void
    {
        $this->releaseWithNzb(1, present: 4, declared: 0);
        DB::table('releases')->where('id', 1)->update(['completion' => 12.5]);

        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertSame(12.5, $this->completionOf(1));
    }

    #[Test]
    public function it_corrects_a_phantom_trailing_file_whether_or_not_the_declared_count_was_recorded(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);
        Search::shouldReceive('updateRelease')->once()->with(2);
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);
        $this->phantomRelease(2, PhantomTrailingSets::base(), declaredfiles: null);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])
            ->expectsOutputToContain('2 matched, 0 unmatched, 0 skipped')
            ->assertSuccessful();

        foreach ([1, 2] as $id) {
            $this->assertSame(100.0, $this->completionOf($id));
            $this->assertSame(PhantomTrailingSets::HELD, $this->declaredFilesOf($id));
        }
    }

    #[Test]
    public function a_set_that_is_not_a_phantom_trailing_file_is_left_as_it_was(): void
    {
        $this->phantomRelease(1, PhantomTrailingSets::lastVolumeNotARemainder(), declaredfiles: PhantomTrailingSets::DECLARED);
        $this->phantomRelease(2, PhantomTrailingSets::lastVolumeNotARemainder(), declaredfiles: null);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])
            ->expectsOutputToContain('0 matched, 2 unmatched, 0 skipped')
            ->assertSuccessful();

        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(1));
        $this->assertSame(PhantomTrailingSets::DECLARED, $this->declaredFilesOf(1));
        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(2));
        $this->assertNull(DB::table('releases')->where('id', 2)->value('declaredfiles'));
    }

    #[Test]
    public function a_reconciled_posting_gets_the_corrected_completion_and_keeps_its_declared_count(): void
    {
        // Late collections merge into a reconciled posting only while the declared counts agree.
        Search::shouldReceive('updateRelease')->once()->with(1);
        ProductionTables::fromAuthority()->create('reconciled_postings', ['id', 'release_id']);
        DB::table('reconciled_postings')->insert(['id' => 1, 'release_id' => 1]);
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])->assertSuccessful();

        $this->assertSame(100.0, $this->completionOf(1));
        $this->assertSame(PhantomTrailingSets::DECLARED, $this->declaredFilesOf(1));
    }

    #[Test]
    public function a_row_another_writer_changed_after_it_was_read_is_skipped(): void
    {
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);
        $this->app->instance(NzbService::class, new class extends NzbService
        {
            public function __construct()
            {
                parent::__construct(app(CollectionCleanupService::class));
            }

            public function readNzbContents(string $guid): string|false
            {
                $contents = parent::readNzbContents($guid);
                // A repair lands between the backfill's read and its write.
                DB::table('releases')->where('guid', $guid)->update(['completion' => 96.5]);

                return $contents;
            }
        });

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])
            ->expectsOutputToContain('0 matched, 0 unmatched, 1 skipped')
            ->assertSuccessful();

        $this->assertSame(96.5, $this->completionOf(1));
        $this->assertSame(PhantomTrailingSets::DECLARED, $this->declaredFilesOf(1));
    }

    #[Test]
    public function a_row_held_by_a_recovery_lease_is_skipped(): void
    {
        DB::statement('ALTER TABLE releases ADD COLUMN recovery_claimed_at DATETIME NULL');
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);
        DB::table('releases')->where('id', 1)->update(['recovery_claimed_at' => now()]);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])
            ->expectsOutputToContain('0 matched, 0 unmatched, 1 skipped')
            ->assertSuccessful();

        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(1));
    }

    #[Test]
    public function the_phantom_trailing_dry_run_reports_and_writes_nothing(): void
    {
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);
        $this->phantomRelease(2, PhantomTrailingSets::lastVolumeNotARemainder(), declaredfiles: PhantomTrailingSets::DECLARED);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('1 matched, 1 unmatched, 0 skipped')
            ->assertSuccessful();

        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(1));
        $this->assertSame(PhantomTrailingSets::DECLARED, $this->declaredFilesOf(1));
    }

    #[Test]
    public function the_phantom_trailing_pass_selects_only_its_population(): void
    {
        // Complete, never measured, and declaring two more: none of them is this pass's business.
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED, completion: 100.0);
        $this->phantomRelease(2, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED, completion: 0.0);
        $this->phantomRelease(3, PhantomTrailingSets::base(), declaredfiles: 14);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true])
            ->expectsOutputToContain('Examined 0 release(s)')
            ->assertSuccessful();

        $this->assertSame(0.0, $this->completionOf(2));
        $this->assertSame(14, $this->declaredFilesOf(3));
    }

    #[Test]
    public function the_default_pass_does_not_pick_up_phantom_trailing_rows(): void
    {
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);

        $this->artisan('releases:backfill-completion')->assertSuccessful();
        $this->artisan('releases:backfill-completion', ['--understated' => true])->assertSuccessful();

        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(1));
        $this->assertSame(PhantomTrailingSets::DECLARED, $this->declaredFilesOf(1));
    }

    #[Test]
    public function phantom_trailing_and_understated_together_are_refused(): void
    {
        $this->phantomRelease(1, PhantomTrailingSets::base(), declaredfiles: PhantomTrailingSets::DECLARED);

        $this->artisan('releases:backfill-completion', ['--phantom-trailing' => true, '--understated' => true])
            ->assertFailed();

        $this->assertSame(round(12 / 13 * 100, 2), $this->completionOf(1));
    }

    /**
     * A release whose stored NZB holds these subjects, one segment per file, stored at the
     * scaled-down figure creation measured.
     *
     * @param  list<string>  $subjects
     */
    private function phantomRelease(int $id, array $subjects, ?int $declaredfiles, float $completion = 92.31): void
    {
        $this->releaseWithNzbFiles($id, $subjects, segmentsPerFile: 1, completion: $completion);
        DB::table('releases')->where('id', $id)->update(['totalpart' => \count($subjects), 'declaredfiles' => $declaredfiles]);
    }

    private function declaredFilesOf(int $id): int
    {
        return (int) DB::table('releases')->where('id', $id)->value('declaredfiles');
    }

    private function obfuscatedReleaseWithNzb(int $id, int $files, int $declaredTotal, float $completion): void
    {
        $subjects = [];

        for ($file = 1; $file <= $files; $file++) {
            $subjects[] = sprintf('[%d/%d] - "9f2c1b%03d" yEnc (1/%d)', $file, $declaredTotal, $file, $declaredTotal);
        }

        $this->releaseWithNzbFiles($id, $subjects, segmentsPerFile: 1, completion: $completion);
    }

    private function releaseWithNzb(int $id, int $present, int $declared): void
    {
        $subject = $declared > 0
            ? 'Example.part01.rar yEnc (1/'.$declared.')'
            : 'Example.part01.rar yEnc';

        $this->releaseWithNzbFiles($id, [$subject], segmentsPerFile: $present, completion: 0.0);
    }

    /**
     * @param  list<string>  $subjects  One subject per `<file>` element.
     */
    private function releaseWithNzbFiles(int $id, array $subjects, int $segmentsPerFile, float $completion): void
    {
        $guid = sprintf('%032x', $id);

        DB::table('releases')->insert([
            'id' => $id,
            'guid' => $guid,
            'nzbstatus' => 1,
            'completion' => $completion,
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        foreach ($subjects as $index => $subject) {
            $xml .= '  <file poster="p@example.org" date="1700000000" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n    <segments>\n";

            for ($number = 1; $number <= $segmentsPerFile; $number++) {
                $xml .= '      <segment bytes="900" number="'.$number.'">file'.$index.'part'.$number.'@host</segment>'."\n";
            }

            $xml .= "    </segments>\n  </file>\n";
        }

        $xml .= '</nzb>'."\n";

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
            guid VARCHAR(64) UNIQUE,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            completion DOUBLE NOT NULL DEFAULT 0,
            totalpart INTEGER NOT NULL DEFAULT 0,
            declaredfiles INTEGER NULL,
            repair_attempted_at DATETIME NULL,
            repair_outcome VARCHAR(16) NULL
        )');
    }
}
