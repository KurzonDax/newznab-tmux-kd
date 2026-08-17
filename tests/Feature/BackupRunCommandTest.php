<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BackupFailed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupRunCommandTest extends TestCase
{
    private string $backupLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupLocation = $this->makeTempDirectory('nntmux-backups');
        $this->createSchema();
        $this->seedSettings();

        config([
            'database.connections.testing.driver' => 'mysql',
            'app.version' => 'test-version',
        ]);

        Carbon::setTestNow('2026-08-17 02:00:00');
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_backup_writes_verified_dump_and_manifest_with_expected_table_tiers(): void
    {
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            if (($process->environment['BACKUP_OUTPUT'] ?? null) !== null) {
                $this->assertLessThanOrEqual(config('nntmux-backup.process_timeout_seconds'), $process->timeout);
                $this->assertGreaterThan($process->timeout, config('nntmux-backup.lock_seconds'));
                file_put_contents($process->environment['BACKUP_OUTPUT'], gzencode("CREATE TABLE users;\n"));
            }

            if (is_array($process->command) && in_array('-t', $process->command, true)) {
                $this->assertGreaterThan(60, $process->timeout);
                $this->assertLessThanOrEqual(config('nntmux-backup.process_timeout_seconds'), $process->timeout);
            }

            return Process::result();
        });

        $this->artisan('backup:run full')
            ->expectsOutputToContain('Full backup completed')
            ->assertSuccessful();

        $setDirectory = $this->backupLocation.'/20260817-020000';
        $dumpPath = $setDirectory.'/full-20260817-0200.sql.gz';
        $manifestPath = $dumpPath.'.manifest.json';

        $this->assertFileExists($dumpPath);
        $this->assertFileExists($manifestPath);
        $this->assertSame("CREATE TABLE users;\n", gzdecode((string) file_get_contents($dumpPath)));

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('full', $manifest['kind']);
        $this->assertSame('20260817-020000', $manifest['set_id']);
        $this->assertSame(['collections', 'settings', 'users'], $manifest['tables']);
        $this->assertSame(['important', 'working'], $manifest['tiers_included']);
        $this->assertSame(hash_file('sha256', $dumpPath), $manifest['sha256']);
        $this->assertSame(filesize($dumpPath), $manifest['bytes']);
        $this->assertSame('test-version', $manifest['app_version']);
        $this->assertArrayHasKey('db_server_version', $manifest);
        $this->assertNotContains('pulse_entries', $manifest['tables']);
    }

    public function test_failed_dump_leaves_no_files_records_failure_and_mails_admin(): void
    {
        config(['nntmux.admin_email' => 'admin@example.test']);
        Mail::fake();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            return Process::result(errorOutput: 'disk write failed', exitCode: 2);
        });

        $this->artisan('backup:run daily')
            ->expectsOutputToContain('Database dump failed: disk write failed')
            ->assertFailed();

        $files = glob($this->backupLocation.'/*/*') ?: [];
        $this->assertSame([], $files);
        $this->assertDatabaseHas('database_backups', [
            'kind' => 'daily',
            'status' => 'failed',
            'error' => 'Database dump failed: disk write failed',
        ]);
        Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail): bool => $mail->kind === 'daily'
            && $mail->error === 'Database dump failed: disk write failed');
    }

    public function test_backup_pause_is_visible_during_dump_and_restores_running_state_afterward(): void
    {
        DB::table('settings')->where('name', 'backup_pause_tmux')->update(['value' => '1']);
        DB::table('settings')->where('name', 'running')->update(['value' => '1']);

        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            if (($process->environment['BACKUP_OUTPUT'] ?? null) !== null) {
                $this->assertSame('0', DB::table('settings')->where('name', 'running')->value('value'));
                $marker = json_decode((string) DB::table('settings')->where('name', 'backup_pause_marker')->value('value'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertNotSame('', $marker['lock_owner'] ?? '');
                file_put_contents($process->environment['BACKUP_OUTPUT'], gzencode("backup\n"));
            }

            return Process::result();
        });

        $this->artisan('backup:run full')->assertSuccessful();

        $this->assertSame('1', DB::table('settings')->where('name', 'running')->value('value'));
        $this->assertSame('', DB::table('settings')->where('name', 'backup_pause_marker')->value('value'));
    }

    public function test_successful_full_purges_oldest_backup_set_as_a_whole(): void
    {
        DB::table('settings')->where('name', 'backup_keep_fulls')->update(['value' => '2']);
        $this->writeExistingBackup('20260801-020000', 'full', '20260801-0200');
        $this->writeExistingBackup('20260801-020000', 'daily', '20260802-0200');
        $this->writeExistingBackup('20260808-020000', 'full', '20260808-0200');

        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            if (($process->environment['BACKUP_OUTPUT'] ?? null) !== null) {
                file_put_contents($process->environment['BACKUP_OUTPUT'], gzencode("new full\n"));
            }

            return Process::result();
        });

        $this->artisan('backup:run full')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->backupLocation.'/20260801-020000');
        $this->assertDirectoryExists($this->backupLocation.'/20260808-020000');
        $this->assertDirectoryExists($this->backupLocation.'/20260817-020000');
    }

    public function test_corrupt_full_does_not_count_toward_retention(): void
    {
        DB::table('settings')->where('name', 'backup_keep_fulls')->update(['value' => '2']);
        $this->writeExistingBackup('20260801-020000', 'full', '20260801-0200');
        $this->writeExistingBackup('20260810-020000', 'full', '20260810-0200');
        file_put_contents($this->backupLocation.'/20260810-020000/full-20260810-0200.sql.gz', 'corrupt');
        $this->fakeSuccessfulDump();

        $this->artisan('backup:run full')->assertSuccessful();

        $this->assertDirectoryExists($this->backupLocation.'/20260801-020000');
        $this->assertDirectoryExists($this->backupLocation.'/20260810-020000');
        $this->assertDirectoryExists($this->backupLocation.'/20260817-020000');
    }

    public function test_catalog_ignores_manifest_with_mismatched_directory_set_id(): void
    {
        $this->writeExistingBackup('20260816-020000', 'full', '20260816-0200');
        $manifestPath = $this->backupLocation.'/20260816-020000/full-20260816-0200.sql.gz.manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['set_id'] = '20260801-020000';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('backup:list')
            ->expectsOutputToContain('No database Backup sets found')
            ->assertSuccessful();

        $this->assertDatabaseCount('database_backups', 0);
    }

    public function test_catalog_ignores_manifest_missing_required_fields(): void
    {
        $this->writeExistingBackup('20260816-020000', 'full', '20260816-0200');
        $manifestPath = $this->backupLocation.'/20260816-020000/full-20260816-0200.sql.gz.manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        unset($manifest['finished_at'], $manifest['tables']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('backup:list')
            ->expectsOutputToContain('No database Backup sets found')
            ->assertSuccessful();

        $this->assertDatabaseCount('database_backups', 0);
    }

    public function test_daily_backup_excludes_working_and_throwaway_tables(): void
    {
        $this->fakeSuccessfulDump();

        $this->artisan('backup:run daily')->assertSuccessful();

        $manifestPath = (glob($this->backupLocation.'/*/daily-*.manifest.json') ?: [])[0] ?? null;
        $this->assertNotNull($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['settings', 'users'], $manifest['tables']);
        $this->assertSame(['important'], $manifest['tiers_included']);
    }

    public function test_daily_does_not_attach_to_a_corrupt_full_set(): void
    {
        $this->writeExistingBackup('20260816-020000', 'full', '20260816-0200');
        file_put_contents($this->backupLocation.'/20260816-020000/full-20260816-0200.sql.gz', 'corrupt');
        $this->fakeSuccessfulDump();

        $this->artisan('backup:run daily')->assertSuccessful();

        $this->assertCount(1, glob($this->backupLocation.'/no-full-yet-*/daily-*.sql.gz') ?: []);
        $this->assertCount(0, glob($this->backupLocation.'/20260816-020000/daily-*.sql.gz') ?: []);
    }

    public function test_full_backup_can_exclude_working_tables(): void
    {
        DB::table('settings')->where('name', 'backup_incl_working')->update(['value' => '0']);
        $this->fakeSuccessfulDump();

        $this->artisan('backup:run full')->assertSuccessful();

        $manifestPath = (glob($this->backupLocation.'/*/full-*.manifest.json') ?: [])[0] ?? null;
        $this->assertNotNull($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['settings', 'users'], $manifest['tables']);
        $this->assertSame(['important'], $manifest['tiers_included']);
    }

    public function test_backup_pause_preserves_a_stopped_tmux_state(): void
    {
        DB::table('settings')->where('name', 'backup_pause_tmux')->update(['value' => '1']);
        DB::table('settings')->where('name', 'running')->update(['value' => '0']);
        $this->fakeSuccessfulDump();

        $this->artisan('backup:run full')->assertSuccessful();

        $this->assertSame('0', DB::table('settings')->where('name', 'running')->value('value'));
        $this->assertSame('', DB::table('settings')->where('name', 'backup_pause_marker')->value('value'));
    }

    public function test_failed_backup_restores_the_prior_running_state(): void
    {
        DB::table('settings')->where('name', 'backup_pause_tmux')->update(['value' => '1']);
        DB::table('settings')->where('name', 'running')->update(['value' => '1']);
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v mariadb-dump || command -v mysqldump']) {
                return Process::result('/usr/bin/mariadb-dump'.PHP_EOL);
            }

            if ($process->command === ['sh', '-c', 'command -v pigz || command -v gzip']) {
                return Process::result('/usr/bin/gzip'.PHP_EOL);
            }

            return Process::result(errorOutput: 'dump interrupted', exitCode: 2);
        });

        $this->artisan('backup:run daily')->assertFailed();

        $this->assertSame('1', DB::table('settings')->where('name', 'running')->value('value'));
        $this->assertSame('', DB::table('settings')->where('name', 'backup_pause_marker')->value('value'));
    }

    public function test_unsupported_database_driver_fails_clearly(): void
    {
        config(['database.connections.testing.driver' => 'pgsql']);
        Mail::fake();
        config(['nntmux.admin_email' => 'admin@example.test']);

        $this->artisan('backup:run full')
            ->expectsOutputToContain("unsupported for the 'pgsql' driver")
            ->assertFailed();

        Mail::assertSent(BackupFailed::class);
    }

    public function test_catalog_write_failure_does_not_hide_original_error_or_alert(): void
    {
        Schema::drop('database_backups');
        config([
            'database.connections.testing.driver' => 'pgsql',
            'nntmux.admin_email' => 'admin@example.test',
        ]);
        Mail::fake();

        $this->artisan('backup:run full')
            ->expectsOutputToContain("unsupported for the 'pgsql' driver")
            ->assertFailed();

        Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail): bool => str_contains($mail->error, 'unsupported'));
    }

    public function test_missing_dump_binary_fails_clearly(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'not found', exitCode: 1));

        $this->artisan('backup:run full')
            ->expectsOutputToContain('Neither mariadb-dump nor mysqldump is available')
            ->assertFailed();
    }

    public function test_run_refuses_when_twice_the_last_backup_size_exceeds_free_space(): void
    {
        $this->writeExistingBackup('20260808-020000', 'full', '20260808-0200');
        config(['nntmux-backup.free_space_multiplier' => PHP_INT_MAX]);

        $this->artisan('backup:run full')
            ->expectsOutputToContain('Insufficient free space for Full backup')
            ->assertFailed();
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('collections', fn (Blueprint $table) => $table->id());
        Schema::create('pulse_entries', fn (Blueprint $table) => $table->id());
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
            ['name' => 'backup_location', 'value' => $this->backupLocation],
            ['name' => 'backup_keep_fulls', 'value' => '4'],
            ['name' => 'backup_pause_tmux', 'value' => '0'],
            ['name' => 'backup_incl_working', 'value' => '1'],
            ['name' => 'backup_dump_binary', 'value' => ''],
            ['name' => 'backup_offsite_after', 'value' => '0'],
            ['name' => 'backup_pause_marker', 'value' => ''],
            ['name' => 'running', 'value' => '0'],
            ['name' => 'monitor_delay', 'value' => '0'],
        ]);
    }

    private function writeExistingBackup(string $setId, string $kind, string $timestamp): void
    {
        $directory = $this->backupLocation.'/'.$setId;
        if (! is_dir($directory)) {
            mkdir($directory);
        }

        $dump = "{$directory}/{$kind}-{$timestamp}.sql.gz";
        file_put_contents($dump, gzencode("{$kind}\n"));
        file_put_contents($dump.'.manifest.json', json_encode([
            'kind' => $kind,
            'started_at' => Carbon::createFromFormat('Ymd-Hi', $timestamp)->toIso8601String(),
            'finished_at' => Carbon::createFromFormat('Ymd-Hi', $timestamp)->toIso8601String(),
            'tables' => ['settings'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => $setId,
        ], JSON_THROW_ON_ERROR));
    }

    private function fakeSuccessfulDump(): void
    {
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
}
