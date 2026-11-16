<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database backups are daily-only (ADR 0016): one Backup time, and retention counted in single backups.
 */
return new class extends Migration
{
    /** @var array<string, array{string, string}> new name => [old name, default] */
    private const array RENAMES = [
        'backup_time' => ['backup_daily_time', '02:00'],
        'backup_keep' => ['backup_keep_fulls', '4'],
    ];

    private const array REMOVED = ['backup_full_dow', 'backup_full_time'];

    private const int OFFSITE_KEEP_MAX = 10;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::RENAMES as $name => [$oldName, $default]) {
            $this->rename($oldName, $name, $default);
        }

        DB::table('settings')->whereIn('name', self::REMOVED)->delete();

        // Zero used to keep every copied backup; the range is now 1 to 10, so it keeps the most allowed.
        $offsiteKeep = DB::table('settings')->where('name', 'backup_offsite_keep')->value('value');
        $keep = filter_var($offsiteKeep, FILTER_VALIDATE_INT);
        if ($offsiteKeep !== null && ($keep === false || $keep < 1 || $keep > self::OFFSITE_KEEP_MAX)) {
            DB::table('settings')->where('name', 'backup_offsite_keep')->update(['value' => (string) self::OFFSITE_KEEP_MAX]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::RENAMES as $name => [$oldName, $default]) {
            $this->rename($name, $oldName, $default);
        }

        DB::table('settings')->insertOrIgnore([
            ['name' => 'backup_full_dow', 'value' => '0'],
            ['name' => 'backup_full_time', 'value' => (string) (DB::table('settings')->where('name', 'backup_daily_time')->value('value') ?? '02:00')],
        ]);
    }

    private function rename(string $from, string $to, string $default): void
    {
        $value = DB::table('settings')->where('name', $from)->value('value');
        DB::table('settings')->insertOrIgnore(['name' => $to, 'value' => (string) ($value ?? $default)]);
        DB::table('settings')->where('name', $from)->delete();
    }
};
