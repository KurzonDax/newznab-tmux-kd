<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
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
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => 'daily']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running requested Daily backup')
            ->assertSuccessful();

        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
        $this->assertCount(1, glob($this->backupLocation.'/*/daily-*.sql.gz') ?: []);
    }

    public function test_pending_request_is_cleared_when_backups_are_disabled(): void
    {
        DB::table('settings')->where('name', 'backup_enabled')->update(['value' => '0']);
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => 'daily']);

        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertSame('', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
        $this->assertCount(0, glob($this->backupLocation.'/*/*.sql.gz') ?: []);
    }

    public function test_overlapping_tick_is_skipped_successfully(): void
    {
        DB::table('settings')->where('name', 'backup_run_request')->update(['value' => 'daily']);
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
        $this->assertSame('daily', DB::table('settings')->where('name', 'backup_run_request')->value('value'));
    }

    public function test_missed_full_slot_is_caught_up_before_daily_work(): void
    {
        $this->artisan('backup:tick')
            ->expectsOutputToContain('Running scheduled Full backup')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->backupLocation.'/*/full-*.sql.gz') ?: []);
        $this->assertCount(0, glob($this->backupLocation.'/*/daily-*.sql.gz') ?: []);
    }

    public function test_daily_is_skipped_on_full_backup_day(): void
    {
        Carbon::setTestNow('2026-08-16 03:00:00');
        $this->writeBackup('20260816-020000', 'full', '20260816-0200');

        $this->artisan('backup:tick')
            ->expectsOutputToContain('No database backup is due')
            ->assertSuccessful();

        $this->assertCount(0, glob($this->backupLocation.'/*/daily-*.sql.gz') ?: []);
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
            $table->string('kind', 10);
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
            ['name' => 'backup_full_dow', 'value' => '0'],
            ['name' => 'backup_full_time', 'value' => '02:00'],
            ['name' => 'backup_daily_time', 'value' => '02:00'],
            ['name' => 'backup_location', 'value' => $this->backupLocation],
            ['name' => 'backup_keep_fulls', 'value' => '4'],
            ['name' => 'backup_pause_tmux', 'value' => '0'],
            ['name' => 'backup_incl_working', 'value' => '1'],
            ['name' => 'backup_dump_binary', 'value' => ''],
            ['name' => 'backup_offsite_after', 'value' => '0'],
            ['name' => 'backup_run_request', 'value' => ''],
            ['name' => 'backup_pause_marker', 'value' => ''],
            ['name' => 'running', 'value' => '0'],
        ]);
    }

    private function writeBackup(string $setId, string $kind, string $timestamp): void
    {
        $set = $this->backupLocation.'/'.$setId;
        mkdir($set);
        $dump = "{$set}/{$kind}-{$timestamp}.sql.gz";
        file_put_contents($dump, gzencode("{$kind}\n"));
        $finishedAt = Carbon::createFromFormat('Ymd-Hi', $timestamp)->toIso8601String();
        file_put_contents($dump.'.manifest.json', json_encode([
            'kind' => $kind,
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
