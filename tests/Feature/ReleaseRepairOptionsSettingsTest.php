<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\Binaries\BinariesConfig;
use App\Services\ReleaseRepair\MissingFileRescanOptions;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The repair engine's and the header re-scan's tunables come from site settings, so an operator
 * retunes a scheduled job from the admin UI rather than by editing the scheduler entry. CLI flags
 * stay as explicit per-run overrides, and the constants stay as the answer when a row says
 * nothing usable.
 */
class ReleaseRepairOptionsSettingsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '0'];
    }

    #[Test]
    public function every_tunable_is_read_from_settings(): void
    {
        $this->setSettings([
            'completionpercent' => '90',
            'repair_floor_completion' => '15',
            'repair_retry_after_hours' => '48',
            'repair_stat_sample_per_file' => '3',
            'repair_max_stat_probes' => '30',
            'repair_limit' => '40',
        ]);

        $options = ReleaseRepairOptions::fromSettings();

        $this->assertSame(90.0, $options->targetCompletion);
        $this->assertSame(15.0, $options->floorCompletion);
        $this->assertSame(48, $options->retryAfterHours);
        $this->assertSame(3, $options->statSamplePerFile);
        $this->assertSame(30, $options->maxStatProbes);
        $this->assertSame(40, ReleaseRepairOptions::limitFromSettings());
    }

    #[Test]
    public function a_cli_flag_beats_the_setting_for_that_run_only(): void
    {
        $this->setSettings([
            'repair_floor_completion' => '15',
            'repair_retry_after_hours' => '48',
            'repair_limit' => '40',
        ]);

        $options = ReleaseRepairOptions::fromSettings(floorCompletion: 5.0, retryAfterHours: 1);

        $this->assertSame(5.0, $options->floorCompletion);
        $this->assertSame(1, $options->retryAfterHours);
        $this->assertSame(7, ReleaseRepairOptions::limitFromSettings(7));

        // Nothing was written back: the next run reads the setting again.
        $this->assertSame(48, ReleaseRepairOptions::fromSettings()->retryAfterHours);
        $this->assertSame(40, ReleaseRepairOptions::limitFromSettings());
    }

    #[Test]
    public function a_missing_or_unusable_row_falls_back_to_the_constant(): void
    {
        // Never seeded, blank because someone cleared the field, and typed into the wrong box.
        $this->setSettings([
            'repair_floor_completion' => '',
            'repair_stat_sample_per_file' => 'two',
        ]);

        $options = ReleaseRepairOptions::fromSettings();

        $this->assertSame(ReleaseRepairOptions::REPAIR_FLOOR_COMPLETION, $options->floorCompletion);
        $this->assertSame(ReleaseRepairOptions::STAT_SAMPLE_PER_FILE, $options->statSamplePerFile);
        $this->assertSame(ReleaseRepairOptions::RETRY_AFTER_HOURS, $options->retryAfterHours);
        $this->assertSame(ReleaseRepairOptions::MAX_STAT_PROBES, $options->maxStatProbes);
        $this->assertSame(ReleaseRepairOptions::DEFAULT_LIMIT, ReleaseRepairOptions::limitFromSettings());
    }

    #[Test]
    public function a_disarmed_sweep_still_gives_repair_a_target_to_work_to(): void
    {
        // `completionpercent = 0` turns the sweep off; a target of 0 would call every release
        // repaired on sight, so the default stands in.
        $this->setSettings(['completionpercent' => '0']);

        $this->assertSame(
            ReleaseRepairOptions::DEFAULT_TARGET_COMPLETION,
            ReleaseRepairOptions::fromSettings()->targetCompletion
        );
    }

    #[Test]
    public function every_re_scan_budget_is_read_from_settings(): void
    {
        $this->setSettings([
            'completionpercent' => '90',
            'repair_retry_after_hours' => '48',
            'rescan_window_minutes' => '45',
            'rescan_max_articles_per_release' => '250000',
            'rescan_max_articles_per_run' => '1000000',
            'rescan_limit' => '25',
            'maxmssgs' => '15000',
        ]);

        $options = MissingFileRescanOptions::fromSettings();

        $this->assertSame(90.0, $options->targetCompletion);
        $this->assertSame(45, $options->windowMinutes);
        $this->assertSame(250000, $options->maxArticlesPerRelease);
        $this->assertSame(1000000, $options->maxArticlesPerRun);
        $this->assertSame(15000, $options->overviewBatchSize, 'XOVER batches follow the header scan.');
        $this->assertSame(25, MissingFileRescanOptions::limitFromSettings());

        // One definition of "give up", shared with the repair engine.
        $this->assertSame(48, $options->retryAfterHours);
    }

    #[Test]
    public function a_zero_message_batch_uses_the_coded_default_at_both_boundaries(): void
    {
        $this->setSettings(['maxmssgs' => '0']);

        $this->assertSame(20000, BinariesConfig::fromSettings()->messageBuffer);
        $this->assertSame(20000, MissingFileRescanOptions::fromSettings()->overviewBatchSize);
    }

    #[Test]
    public function a_missing_message_batch_uses_the_coded_default_at_both_boundaries(): void
    {
        DB::table('settings')->where('name', 'maxmssgs')->delete();
        Settings::forgetCachedSettings();

        $this->assertSame(20000, BinariesConfig::fromSettings()->messageBuffer);
        $this->assertSame(20000, MissingFileRescanOptions::fromSettings()->overviewBatchSize);
    }

    #[Test]
    public function a_negative_message_batch_uses_the_coded_default(): void
    {
        $this->setSettings(['maxmssgs' => '-5']);

        $this->assertSame(20000, MissingFileRescanOptions::fromSettings()->overviewBatchSize);
    }

    #[Test]
    public function a_small_positive_message_batch_passes_through(): void
    {
        $this->setSettings(['maxmssgs' => '1']);

        $this->assertSame(1, MissingFileRescanOptions::fromSettings()->overviewBatchSize);
    }

    #[Test]
    public function direct_construction_normalizes_a_non_positive_message_batch(): void
    {
        $this->assertSame(20000, new MissingFileRescanOptions(overviewBatchSize: 0)->overviewBatchSize);
        $this->assertSame(20000, new MissingFileRescanOptions(overviewBatchSize: -5)->overviewBatchSize);
    }

    #[Test]
    public function a_re_scan_cli_flag_beats_the_setting_for_that_run_only(): void
    {
        $this->setSettings(['rescan_window_minutes' => '45', 'rescan_limit' => '25']);

        $this->assertSame(5, MissingFileRescanOptions::fromSettings(windowMinutes: 5)->windowMinutes);
        $this->assertSame(3, MissingFileRescanOptions::limitFromSettings(3));

        $this->assertSame(45, MissingFileRescanOptions::fromSettings()->windowMinutes);
        $this->assertSame(25, MissingFileRescanOptions::limitFromSettings());
    }

    #[Test]
    public function a_missing_or_unusable_re_scan_row_falls_back_to_the_constant(): void
    {
        $this->setSettings([
            'rescan_window_minutes' => '',
            'rescan_max_articles_per_release' => 'lots',
            'maxmssgs' => '',
        ]);

        $options = MissingFileRescanOptions::fromSettings();

        $this->assertSame(MissingFileRescanOptions::DEFAULT_WINDOW_MINUTES, $options->windowMinutes);
        $this->assertSame(MissingFileRescanOptions::DEFAULT_MAX_ARTICLES_PER_RELEASE, $options->maxArticlesPerRelease);
        $this->assertSame(MissingFileRescanOptions::DEFAULT_MAX_ARTICLES_PER_RUN, $options->maxArticlesPerRun);
        $this->assertSame(MissingFileRescanOptions::DEFAULT_OVERVIEW_BATCH, $options->overviewBatchSize);
        $this->assertSame(MissingFileRescanOptions::DEFAULT_LIMIT, MissingFileRescanOptions::limitFromSettings());
    }

    /**
     * @param  array<string, string>  $values
     */
    private function setSettings(array $values): void
    {
        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }

        Settings::forgetCachedSettings();
    }
}
