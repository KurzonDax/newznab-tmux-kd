<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\BackupFailed;
use App\Services\Backup\BackupCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BackupOffsiteCommandTest extends TestCase
{
    public function test_allow_local_copy_is_atomic_verified_and_idempotent(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
        ]);
        $this->writeBackup($source, '20260816-020000');

        $copies = 0;
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$copies) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            if (isset($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP'])) {
                $copies++;
                $this->assertLessThanOrEqual(config('nntmux-backup.offsite_process_timeout_seconds'), $process->timeout);
                $this->assertGreaterThan($process->timeout, config('nntmux-backup.offsite_lock_seconds'));
                copy($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP']);
            }

            return Process::result();
        });

        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('1 file copied')->assertSuccessful();

        $destinationDump = $destination.'/20260816-020000/full-20260816-0200.sql.gz';
        $this->assertFileExists($destinationDump);
        $this->assertFileExists($destinationDump.'.manifest.json');
        $this->assertSame(
            hash_file('sha256', $source.'/20260816-020000/full-20260816-0200.sql.gz'),
            hash_file('sha256', $destinationDump),
        );

        $this->assertDatabaseHas('database_backups', [
            'set_id' => '20260816-020000',
            'offsite_status' => 'copied',
        ]);
        $this->assertSame([], glob($destination.'/20260816-020000/.tmp-*') ?: []);

        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('0 files copied')->assertSuccessful();

        $this->assertSame(1, $copies);

        file_put_contents($destinationDump, 'corrupt');
        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('1 file copied')->assertSuccessful();

        $this->assertSame(2, $copies);
        $this->assertSame(
            hash_file('sha256', $source.'/20260816-020000/full-20260816-0200.sql.gz'),
            hash_file('sha256', $destinationDump),
        );

        file_put_contents($destinationDump.'.manifest.json', '{"corrupt":true}');
        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('1 file copied')->assertSuccessful();

        $this->assertSame(3, $copies);
        $this->assertSame(
            file_get_contents($source.'/20260816-020000/full-20260816-0200.sql.gz.manifest.json'),
            file_get_contents($destinationDump.'.manifest.json'),
        );
    }

    public function test_same_filesystem_destination_is_refused_without_explicit_bypass(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
        ]);
        $this->writeBackup($source, '20260816-020000');

        $this->artisan('backup:offsite', ['--destination' => $destination])
            ->expectsOutputToContain('not on a separately mounted filesystem')
            ->assertFailed();

        $this->assertSame([], glob($destination.'/*') ?: []);
    }

    public function test_copy_failure_marks_set_and_alerts_admin(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
        ]);
        $this->writeBackup($source, '20260816-020000');
        config(['nntmux.admin_email' => 'admin@example.test']);
        Mail::fake();

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            return Process::result(errorOutput: 'destination disconnected', exitCode: 12);
        });

        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('Off-site rsync failed: destination disconnected')->assertFailed();

        $this->assertDatabaseHas('database_backups', [
            'set_id' => '20260816-020000',
            'offsite_status' => 'failed',
            'error' => 'Off-site rsync failed: destination disconnected',
        ]);
        Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail): bool => $mail->offsite);
    }

    public function test_multi_file_set_is_not_marked_copied_until_every_file_succeeds(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '0'],
        ]);
        $this->writeBackup($source, '20260816-020000');
        $this->writeDailyBackup($source, '20260816-020000');
        $copyAttempt = 0;

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use (&$copyAttempt) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            $copyAttempt++;
            if ($copyAttempt === 1) {
                copy($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP']);

                return Process::result();
            }

            return Process::result(errorOutput: 'connection dropped', exitCode: 12);
        });

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertFailed();

        $this->assertDatabaseMissing('database_backups', [
            'set_id' => '20260816-020000',
            'offsite_status' => 'copied',
        ]);
        $this->assertDatabaseHas('database_backups', [
            'set_id' => '20260816-020000',
            'offsite_status' => 'failed',
        ]);
        $this->assertFileExists($destination.'/20260816-020000/full-20260816-0200.sql.gz.manifest.json');
        $this->assertFileDoesNotExist($destination.'/20260816-020000/daily-20260817-0200.sql.gz.manifest.json');
    }

    public function test_destination_retention_removes_oldest_complete_sets(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '2'],
        ]);
        $this->writeBackup($source, '20260802-020000');
        $this->writeBackup($source, '20260809-020000');
        $this->writeBackup($source, '20260816-020000');

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            if (isset($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP'])) {
                copy($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP']);
            }

            return Process::result();
        });

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($destination.'/20260802-020000');
        $this->assertDirectoryExists($destination.'/20260809-020000');
        $this->assertDirectoryExists($destination.'/20260816-020000');
    }

    public function test_destination_retention_includes_verified_daily_only_sets(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '2'],
        ]);
        $this->writeDailyOnlyBackup($source, 'no-full-yet-20260814-020000', '20260814-0200');
        $this->writeDailyOnlyBackup($source, 'no-full-yet-20260815-020000', '20260815-0200');
        $this->writeDailyOnlyBackup($source, 'no-full-yet-20260816-020000', '20260816-0200');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($destination.'/no-full-yet-20260814-020000');
        $this->assertDirectoryExists($destination.'/no-full-yet-20260815-020000');
        $this->assertDirectoryExists($destination.'/no-full-yet-20260816-020000');
    }

    public function test_corrupt_destination_set_does_not_evict_verified_set(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '2'],
        ]);
        $this->writeBackup($destination, '20260801-020000');
        $this->writeBackup($destination, '20260810-020000');
        file_put_contents($destination.'/20260810-020000/full-20260816-0200.sql.gz', 'corrupt');
        $this->writeBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        $this->assertDirectoryExists($destination.'/20260801-020000');
        $this->assertDirectoryExists($destination.'/20260810-020000');
        $this->assertDirectoryExists($destination.'/20260816-020000');
    }

    public function test_concurrent_offsite_copy_is_skipped_successfully(): void
    {
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
        $lock = Cache::lock('database-backup-offsite', 600);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('backup:offsite')
                ->expectsOutputToContain('already running; skipped')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_checksum_stops_when_operation_deadline_has_expired(): void
    {
        $path = $this->makeTempPath('nntmux-backup-checksum', '.sql.gz');
        file_put_contents($path, gzencode("backup\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum exceeded its operation deadline');

        $this->app->make(BackupCatalog::class)->checksum($path, microtime(true) - 1);
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name', 25)->primary();
            $table->text('value')->nullable();
        });
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

    private function writeBackup(string $location, string $setId): void
    {
        $set = $location.'/'.$setId;
        mkdir($set);
        $dump = $set.'/full-20260816-0200.sql.gz';
        file_put_contents($dump, gzencode("full backup\n"));
        $timestamp = Carbon::createFromFormat('Ymd-His', $setId)->toIso8601String();
        file_put_contents($dump.'.manifest.json', json_encode([
            'kind' => 'full',
            'started_at' => $timestamp,
            'finished_at' => $timestamp,
            'tables' => ['settings'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => $setId,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeDailyBackup(string $location, string $setId): void
    {
        $this->writeBackupFile($location.'/'.$setId, $setId, 'daily', '20260817-0200');
    }

    private function writeDailyOnlyBackup(string $location, string $setId, string $fileTimestamp): void
    {
        $set = $location.'/'.$setId;
        mkdir($set);
        $this->writeBackupFile($set, $setId, 'daily', $fileTimestamp);
    }

    private function writeBackupFile(string $set, string $setId, string $kind, string $fileTimestamp): void
    {
        $dump = "{$set}/{$kind}-{$fileTimestamp}.sql.gz";
        file_put_contents($dump, gzencode("{$kind} backup\n"));
        $timestamp = Carbon::createFromFormat('Ymd-Hi', $fileTimestamp)->toIso8601String();
        file_put_contents($dump.'.manifest.json', json_encode([
            'kind' => $kind,
            'started_at' => $timestamp,
            'finished_at' => $timestamp,
            'tables' => ['settings'],
            'tiers_included' => ['important'],
            'bytes' => filesize($dump),
            'sha256' => hash_file('sha256', $dump),
            'app_version' => 'test',
            'db_server_version' => 'test',
            'set_id' => $setId,
        ], JSON_THROW_ON_ERROR));
    }

    private function fakeSuccessfulCopies(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            if (isset($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP'])) {
                copy($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP']);
            }

            return Process::result();
        });
    }
}
