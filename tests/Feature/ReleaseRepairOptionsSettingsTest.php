<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\ReleaseRepair\ReleaseRepairOptions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * The repair engine's tunables come from site settings, so an operator retunes a scheduled job
 * from the admin UI rather than by editing the scheduler entry. CLI flags stay as explicit
 * per-run overrides, and the constants stay as the answer when a row says nothing usable.
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
