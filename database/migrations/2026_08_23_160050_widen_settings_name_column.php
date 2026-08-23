<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen settings.name and repair keys truncated by non-strict MySQL/MariaDB.
 *
 * This application deliberately does not enable strict SQL mode globally, so the
 * former varchar(25) primary key silently truncated longer setting names. The
 * ambiguous rescan collision cannot preserve a value reliably and uses defaults.
 */
return new class extends Migration
{
    private const int LEGACY_NAME_LENGTH = 25;

    /** @var array<string, string> */
    private const array DEFAULTS = [
        'audio_preview_start_seconds' => '10',
        'audio_segments_to_download' => '12',
        'discard_executable_extensions' => 'dll|exe|msi|scr|com|bat|cmd|pif',
        'repair_stat_sample_per_file' => '2',
        'rescan_max_articles_per_release' => '500000',
        'rescan_max_articles_per_run' => '5000000',
    ];

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            DB::statement("ALTER TABLE `settings` MODIFY `name` VARCHAR(255) NOT NULL DEFAULT ''");
        }

        $settingsByLegacyName = [];

        foreach (self::DEFAULTS as $name => $default) {
            $legacyName = substr($name, 0, self::LEGACY_NAME_LENGTH);
            $settingsByLegacyName[$legacyName][$name] = $default;
        }

        foreach ($settingsByLegacyName as $legacyName => $settings) {
            if (count($settings) > 1) {
                foreach ($settings as $name => $default) {
                    DB::table('settings')->insertOrIgnore(['name' => $name, 'value' => $default]);
                }

                continue;
            }

            $name = array_key_first($settings);

            if (DB::table('settings')->where('name', $name)->exists()) {
                continue;
            }

            $moved = DB::table('settings')
                ->where('name', $legacyName)
                ->update(['name' => $name]);

            if ($moved === 0) {
                DB::table('settings')->insertOrIgnore([
                    'name' => $name,
                    'value' => $settings[$name],
                ]);
            }
        }

        DB::table('settings')->whereIn('name', array_keys($settingsByLegacyName))->delete();
    }

    /**
     * Narrowing would truncate repaired keys again, so rollback is intentionally a no-op.
     */
    public function down(): void {}
};
