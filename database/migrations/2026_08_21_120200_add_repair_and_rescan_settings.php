<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The repair engine's and the header re-scan's tunables, as site settings.
 *
 * An operator tuning a scheduled job edits site settings in the admin UI; they do not edit the
 * scheduler entry. The knobs these join -- `completionpercent`, `delaytime`, `partrepair`,
 * `maxpartrepair` -- are all already `settings` rows on the Usenet Settings section.
 *
 * Values match the constants they replace, so an existing install behaves identically until
 * someone changes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore($this->settings());
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('name', array_column($this->settings(), 'name'))->delete();
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function settings(): array
    {
        return [
            ['name' => 'repair_retry_after_hours', 'value' => '72'],
            ['name' => 'repair_floor_completion', 'value' => '10'],
            ['name' => 'repair_stat_sample_per_file', 'value' => '2'],
            ['name' => 'repair_max_stat_probes', 'value' => '20'],
            ['name' => 'repair_limit', 'value' => '250'],
            ['name' => 'rescan_max_articles_per_release', 'value' => '500000'],
            ['name' => 'rescan_max_articles_per_run', 'value' => '5000000'],
            ['name' => 'rescan_window_minutes', 'value' => '30'],
            ['name' => 'rescan_limit', 'value' => '100'],
        ];
    }
};
