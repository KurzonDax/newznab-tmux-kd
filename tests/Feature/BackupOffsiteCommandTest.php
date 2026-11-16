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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CountingBackupCatalog;
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
            ['name' => 'backup_offsite_keep', 'value' => '7'],
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

        $destinationDump = $destination.'/20260816-020000/backup-20260816-0200.sql.gz';
        $this->assertFileExists($destinationDump);
        $this->assertFileExists($destinationDump.'.manifest.json');
        $this->assertSame(
            hash_file('sha256', $source.'/20260816-020000/backup-20260816-0200.sql.gz'),
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
            hash_file('sha256', $source.'/20260816-020000/backup-20260816-0200.sql.gz'),
            hash_file('sha256', $destinationDump),
        );

        file_put_contents($destinationDump.'.manifest.json', '{"corrupt":true}');
        $this->artisan('backup:offsite', [
            '--destination' => $destination,
            '--allow-local' => true,
        ])->expectsOutputToContain('1 file copied')->assertSuccessful();

        $this->assertSame(3, $copies);
        $this->assertSame(
            file_get_contents($source.'/20260816-020000/backup-20260816-0200.sql.gz.manifest.json'),
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
            ['name' => 'backup_offsite_keep', 'value' => '7'],
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
            ['name' => 'backup_offsite_keep', 'value' => '7'],
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

    public function test_copy_hashes_only_new_temp_copies_and_never_existing_backups(): void
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
        $this->writeBackup($source, '20260816-020000', 'full');
        $this->writeDailyBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();
        $catalog = CountingBackupCatalog::install($this->app);

        $this->artisan('backup:offsite', ['--allow-local' => true])
            ->expectsOutputToContain('2 files copied')
            ->assertSuccessful();

        $this->assertSame(
            ['.tmp-daily-20260817-0200.sql.gz', '.tmp-full-20260816-0200.sql.gz'],
            $this->sortedBasenames($catalog->checksummed),
        );

        $catalog->checksummed = [];
        $this->artisan('backup:offsite', ['--allow-local' => true])
            ->expectsOutputToContain('0 files copied')
            ->assertSuccessful();

        $this->assertSame([], $catalog->checksummed);
        $this->assertDirectoryExists($destination.'/20260801-020000');
    }

    public function test_copy_whose_bytes_differ_at_the_same_size_is_refused(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
            ['name' => 'backup_offsite_keep', 'value' => '7'],
        ]);
        $this->writeBackup($source, '20260816-020000');
        Mail::fake();

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if ($process->command === ['sh', '-c', 'command -v rsync']) {
                return Process::result('/usr/bin/rsync'.PHP_EOL);
            }

            if (isset($process->environment['OFFSITE_SOURCE'], $process->environment['OFFSITE_TEMP'])) {
                $bytes = (string) file_get_contents($process->environment['OFFSITE_SOURCE']);
                file_put_contents($process->environment['OFFSITE_TEMP'], strrev($bytes));
            }

            return Process::result();
        });

        $this->artisan('backup:offsite', ['--allow-local' => true])
            ->expectsOutputToContain('Off-site checksum verification failed for 20260816-020000/backup-20260816-0200.sql.gz')
            ->assertFailed();

        $this->assertFileDoesNotExist($destination.'/20260816-020000/backup-20260816-0200.sql.gz');
        $this->assertFileDoesNotExist($destination.'/20260816-020000/backup-20260816-0200.sql.gz.manifest.json');
        $this->assertSame([], glob($destination.'/20260816-020000/.tmp-*') ?: []);
        $this->assertDatabaseHas('database_backups', [
            'set_id' => '20260816-020000',
            'offsite_status' => 'failed',
        ]);
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
            ['name' => 'backup_offsite_keep', 'value' => '7'],
        ]);
        $this->writeBackup($source, '20260816-020000', 'full');
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

    public function test_destination_retention_runs_when_every_file_is_already_copied(): void
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
        $this->writeBackup($source, '20260809-020000');
        $this->writeBackup($source, '20260816-020000');
        $this->writeBackup($destination, '20260802-020000');
        $this->writeBackup($destination, '20260809-020000');
        $this->writeBackup($destination, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])
            ->expectsOutputToContain('0 files copied')
            ->assertSuccessful();

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
        $this->writeBackup($destination, '20260802-020000', 'full');
        $this->writeBackupFile($destination.'/20260802-020000', '20260802-020000', 'daily', '20260803-0200');
        $this->writeDailyOnlyBackup($source, 'no-full-yet-20260808-020000', '20260808-0200');
        $this->writeBackupFile($source.'/no-full-yet-20260808-020000', 'no-full-yet-20260808-020000', 'daily', '20260809-0200');
        $this->writeBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        // The pre-change folder with two Dailies is one of the two kept, and stays whole.
        $this->assertDirectoryDoesNotExist($destination.'/20260802-020000');
        $this->assertFileExists($destination.'/no-full-yet-20260808-020000/daily-20260808-0200.sql.gz');
        $this->assertFileExists($destination.'/no-full-yet-20260808-020000/daily-20260809-0200.sql.gz');
        $this->assertDirectoryExists($destination.'/20260816-020000');
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
        file_put_contents($destination.'/20260810-020000/backup-20260816-0200.sql.gz', 'corrupt');
        $this->writeBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        $this->assertDirectoryExists($destination.'/20260801-020000');
        $this->assertDirectoryExists($destination.'/20260810-020000');
        $this->assertDirectoryExists($destination.'/20260816-020000');
    }

    public function test_copy_sends_only_the_newest_verified_local_folders(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        $this->seedOffsiteSettings($source, $destination, '2');
        $this->writeBackup($source, '20260802-020000');
        $this->writeBackup($source, '20260809-020000');
        $this->writeBackup($source, '20260812-020000');
        file_put_contents($source.'/20260812-020000/backup-20260816-0200.sql.gz', 'corrupt');
        $this->writeBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])
            ->expectsOutputToContain('2 files copied; 0 already complete across 2 backups.')
            ->assertSuccessful();

        $this->assertSame(['20260809-020000', '20260816-020000'], $this->folders($destination));
        $this->assertSame(['20260809-020000', '20260816-020000'], DB::table('database_backups')
            ->where('offsite_status', 'copied')->orderBy('set_id')->pluck('set_id')->all());
    }

    public function test_keep_option_replaces_the_stored_count(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        $this->seedOffsiteSettings($source, $destination, '7');
        $this->writeBackup($source, '20260809-020000');
        $this->writeBackup($source, '20260816-020000');
        $this->writeBackup($destination, '20260802-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true, '--keep' => '1'])
            ->expectsOutputToContain('1 file copied; 0 already complete across 1 backups.')
            ->assertSuccessful();

        $this->assertSame(['20260816-020000'], $this->folders($destination));
    }

    #[DataProvider('invalidKeepOptions')]
    public function test_keep_option_outside_one_to_ten_is_rejected(string $keep): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        $this->seedOffsiteSettings($source, $destination, '7');
        $this->writeBackup($destination, '20260802-020000');
        Process::preventStrayProcesses();
        Process::fake();

        $this->artisan('backup:offsite', ['--allow-local' => true, '--keep' => $keep])
            ->expectsOutputToContain('--keep must be a whole number from 1 to 10.')
            ->assertExitCode(2);

        Process::assertNothingRan();
        $this->assertSame(['20260802-020000'], $this->folders($destination));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeepOptions(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'eleven' => ['11'], 'fraction' => ['2.5'], 'text' => ['all']];
    }

    #[DataProvider('storedOffsiteKeeps')]
    public function test_stored_offsite_keep_resolves_into_the_one_to_ten_range(?string $stored, int $kept): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        $this->seedOffsiteSettings($source, $destination, $stored);
        for ($day = 1; $day <= 12; $day++) {
            $this->writeBackup($source, sprintf('202608%02d-020000', $day));
        }
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        $expected = array_map(static fn (int $day): string => sprintf('202608%02d-020000', $day), range(13 - $kept, 12));
        $this->assertSame($expected, $this->folders($destination));
    }

    /**
     * @return array<string, array{?string, int}>
     */
    public static function storedOffsiteKeeps(): array
    {
        return [
            'missing' => [null, 7],
            'blank' => ['', 7],
            'zero' => ['0', 7],
            'negative' => ['-2', 7],
            'three' => ['3', 3],
            'ten' => ['10', 10],
            'above ten' => ['25', 10],
        ];
    }

    public function test_destination_retention_removes_every_folder_older_than_the_oldest_kept_folder(): void
    {
        $source = $this->makeTempDirectory('nntmux-offsite-source');
        $destination = $this->makeTempDirectory('nntmux-offsite-destination');
        $this->createSchema();
        $this->seedOffsiteSettings($source, $destination, '2');
        mkdir($destination.'/20260801-020000');
        file_put_contents($destination.'/20260801-020000/.tmp-backup-20260801-0200.sql.gz', 'rsync failed part-way');
        $this->writeBackup($destination, '20260803-020000');
        file_put_contents($destination.'/20260803-020000/backup-20260816-0200.sql.gz', 'corrupt');
        $this->writeBackup($destination, '20260805-020000');
        mkdir($destination.'/lost+found');
        $this->writeBackup($destination, '20260814-020000');
        file_put_contents($destination.'/20260814-020000/backup-20260816-0200.sql.gz', 'corrupt');
        $this->writeBackup($source, '20260813-020000');
        $this->writeBackup($source, '20260816-020000');
        $this->fakeSuccessfulCopies();

        $this->artisan('backup:offsite', ['--allow-local' => true])->assertSuccessful();

        // 0816 and 0813 are kept; the unverified 0814 between them stays; everything older goes.
        $this->assertSame(['20260813-020000', '20260814-020000', '20260816-020000', 'lost+found'], $this->folders($destination));
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

    /**
     * Writes a verified one-file folder; a kind writes a pre-change Full or Daily file and manifest.
     */
    private function writeBackup(string $location, string $setId, ?string $kind = null): void
    {
        $set = $location.'/'.$setId;
        mkdir($set);
        $dump = sprintf('%s/%s-20260816-0200.sql.gz', $set, $kind ?? 'backup');
        file_put_contents($dump, gzencode(($kind ?? 'daily-only')." backup\n"));
        $timestamp = Carbon::createFromFormat('Ymd-His', $setId)->toIso8601String();
        file_put_contents($dump.'.manifest.json', json_encode([
            ...($kind === null ? [] : ['kind' => $kind]),
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

    private function seedOffsiteSettings(string $source, string $destination, ?string $keep): void
    {
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'backup_location', 'value' => $source],
            ['name' => 'backup_offsite_path', 'value' => $destination],
        ]);
        if ($keep !== null) {
            DB::table('settings')->insert(['name' => 'backup_offsite_keep', 'value' => $keep]);
        }
    }

    /**
     * @return list<string>
     */
    private function folders(string $location): array
    {
        $folders = array_map(basename(...), glob($location.'/*', GLOB_ONLYDIR) ?: []);
        sort($folders);

        return $folders;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function sortedBasenames(array $paths): array
    {
        $names = array_map(basename(...), $paths);
        sort($names);

        return $names;
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
