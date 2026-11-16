<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backup\BackupScheduleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountingBackupCatalog;
use Tests\TestCase;

class BackupTickCommandTest extends TestCase
{
    private string $backupLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupLocation = $this->makeTempDirectory('nntmux-backup-tick');
        $this->createSchema();
        $this->seedSettings();
        config(['database.connections.testing.driver' => 'mysql']);
        Carbon::setTestNow('2026-08-17 01:00:00');
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            if (($process->environment['BACKUP_OUTPUT'] ?? null) !== null) {
                file_put_contents($process->environment['BACKUP_OUTPUT'], gzencode("backup\n"));
            }

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_run_now_request_is_consumed_and_executed(): void
    {
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => '1']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running requested backup')
            ->assertSuccessful();

        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
        $this->assertCount(1, glob($this->backupLocation.'/*/backup-*.sql.gz') ?: []);
    }

    #[DataProvider('preChangeRequests')]
    public function test_pending_pre_change_request_runs_one_backup(string $request): void
    {
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => $request]);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running requested backup')
            ->assertSuccessful();

        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
        $this->assertSame(['20260817-010000'], array_map(basename(...), glob($this->backupLocation.'/*') ?: []));
        $this->assertCount(1, glob($this->backupLocation.'/*/backup-*.sql.gz') ?: []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function preChangeRequests(): array
    {
        return ['full' => ['full'], 'daily' => ['daily']];
    }

    public function test_pending_request_is_cleared_when_backups_are_disabled(): void
    {
        DB::table('settings')->where('name', 'backup_enabled')->update(['value' => '0']);
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => '1']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
        $this->assertCount(0, glob($this->backupLocation.'/*/*.sql.gz') ?: []);
    }

    public function test_overlapping_tick_is_skipped_successfully(): void
    {
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => '1']);
        $lock = Cache::lock('database-backup-run', 600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('backup:tick')
                ->expectsOutputToContain('already running; skipped')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertCount(0, glob($this->backupLocation.'/*/*.sql.gz') ?: []);
        $this->assertSame('1', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
    }

    public function test_scheduled_tick_skipped_by_the_lock_is_followed_by_a_backup_at_the_next_free_tick(): void
    {
        Carbon::setTestNow('2026-08-17 02:00:00');
        $this->writeBackup('20260816-020000', null, '20260816-0200');
        $lock = Cache::lock('database-backup-run', 600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('backup:tick')
                ->expectsOutputToContain('already running; skipped')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }

        Carbon::setTestNow('2026-08-17 02:01:00');
        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running scheduled backup')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->backupLocation.'/20260817-020100/backup-20260817-0201.sql.gz') ?: []);
    }

    public function test_failed_scheduled_run_is_not_retried_until_the_next_slot(): void
    {
        Carbon::setTestNow('2026-08-17 02:00:00');
        $this->writeBackup('20260816-020000', null, '20260816-0200');
        DB::table('settings')->where('name', 'backup_dump_binary')->update(['value' => '/missing/mariadb-dump']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running scheduled backup')
            ->assertFailed();
        $this->assertSame(1, DB::table('database_backups')->where('status', 'failed')->count());

        DB::table('settings')->where('name', 'backup_dump_binary')->update(['value' => '']);
        foreach (['2026-08-17 02:01:00', '2026-08-17 23:59:00'] as $now) {
            Carbon::setTestNow($now);
            $this->artisan('backup:tick')
                ->expectsOutputToContain('No database backup is due')
                ->assertSuccessful();
        }

        Carbon::setTestNow('2026-08-18 02:00:00');
        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running scheduled backup')
            ->assertSuccessful();
        $this->assertCount(1, glob($this->backupLocation.'/20260818-020000/backup-*.sql.gz') ?: []);
    }

    public function test_next_run_is_the_next_backup_time_slot(): void
    {
        DB::table('settings')->where('name', 'backup_time')->update(['value' => '03:30']);
        $schedule = $this->app->make(BackupScheduleService::class);

        $this->assertSame('2026-08-17 03:30', $schedule->nextRun(Carbon::parse('2026-08-17 01:00:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-08-18 03:30', $schedule->nextRun(Carbon::parse('2026-08-17 03:30:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-08-19 03:30', $schedule->nextRun(Carbon::parse('2026-08-18 22:00:00'))->format('Y-m-d H:i'));

        // A stored time that is not a valid H:i falls back to 02:00 instead of rolling into another day.
        DB::table('settings')->where('name', 'backup_time')->update(['value' => '25:00']);
        $this->assertSame('2026-08-18 02:00', $schedule->nextRun(Carbon::parse('2026-08-17 23:00:00'))->format('Y-m-d H:i'));
    }

    public function test_missed_full_slot_is_caught_up_before_daily_work(): void
    {
        // 01:00 on the 17th: the last attempt (the 15th) predates the 16th's 02:00 slot.
        $this->writeBackup('20260815-020000', null, '20260815-0200');

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running scheduled backup')
            ->assertSuccessful();
        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->backupLocation.'/20260817-010000/backup-20260817-0100.sql.gz') ?: []);
        $this->assertCount(2, glob($this->backupLocation.'/*/*.sql.gz') ?: []);
    }

    public function test_daily_is_skipped_on_full_backup_day(): void
    {
        Carbon::setTestNow('2026-08-16 03:00:00');
        $this->writeBackup('20260816-020000', null, '20260816-0200');

        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->backupLocation.'/*/*.sql.gz') ?: []);
    }

    public function test_tick_with_nothing_due_does_not_hash_existing_backups(): void
    {
        $this->writeBackup('20260816-020000', 'full', '20260816-0200');
        $this->writeBackup('20260816-020000', 'daily', '20260817-0005');
        $catalog = CountingBackupCatalog::install($this->app);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertSame([], $catalog->checksummed);
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('set_id');
            $table->text('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);
            $table->string('offsite_status', 20)->nullable();
            $table->timestamp('offsite_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    private function seedSettings(): void
    {
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_enabled', 'value' => '1'],
            ['name' => 'backup_time', 'value' => '02:00'],
            ['name' => 'backup_location', 'value' => $this->backupLocation],
            ['name' => 'backup_keep', 'value' => '4'],
            ['name' => 'backup_pause_tmux', 'value' => '0'],
            ['name' => 'backup_incl_working', 'value' => '1'],
            ['name' => 'backup_dump_binary', 'value' => ''],
            ['name' => 'backup_offsite_after', 'value' => '0'],
            ['name' => 'backup_run_request', 'value' => ''],
            ['name' => 'backup_pause_marker', 'value' => ''],
            ['name' => 'running', 'value' => '0'],
        ]);
    }

    /**
     * Writes a verified backup; a kind writes a pre-change Full or Daily file and manifest.
     */
    private function writeBackup(string $setId, ?string $kind, string $timestamp): void
    {
        $set = $this->backupLocation.'/'.$setId;
        if (! is_dir($set)) {
            mkdir($set);
        }
        $dump = sprintf('%s/%s-%s.sql.gz', $set, $kind ?? 'backup', $timestamp);
        file_put_contents($dump, gzencode(($kind ?? 'backup')."\n"));
        $finishedAt = Carbon::createFromFormat('Ymd-Hi', $timestamp)->toIso8601String();
        file_put_contents($dump.'.manifest.json', json_encode([
            ...($kind === null ? [] : ['kind' => $kind]),
            'started_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'tables' => ['settings'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => $setId,
        ], JSON_THROW_ON_ERROR));
    }
}
