<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\LogRetentionService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LogsPruneCommandTest extends TestCase
{
    private string $logsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logsDirectory = $this->makeTempDirectory('nntmux-log-retention');

        // The command resolves the service from the container, so pointing it at a
        // temporary directory keeps every assertion away from the real storage/logs.
        $this->app->bind(
            LogRetentionService::class,
            fn (): LogRetentionService => new LogRetentionService($this->logsDirectory),
        );

        config([
            'logging.channels.daily.days' => 7,
            'nntmux.log_retention.rotate_size_mb' => 1,
        ]);

        Carbon::setTestNow('2026-08-20 02:15:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_oversized_unmanaged_log_is_rotated_with_a_date_suffix(): void
    {
        $live = $this->writeLog('schedule.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($live);
        $this->assertFileExists($this->path('schedule-2026-08-20.log'));
    }

    public function test_small_unmanaged_log_is_left_alone(): void
    {
        $live = $this->writeLog('schedule.log', 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($live);
        $this->assertFileDoesNotExist($this->path('schedule-2026-08-20.log'));
    }

    public function test_rotation_does_not_lose_writes_from_an_appending_cron_redirect(): void
    {
        $existing = str_repeat("scheduled run\n", 100_000);
        $live = $this->writeLog('schedule.log', 0);
        file_put_contents($live, $existing);

        $this->artisan('logs:prune')->assertSuccessful();

        // The crontab `>> storage/logs/schedule.log` redirect reopens the path on the
        // next invocation, so the rename must leave both halves intact.
        file_put_contents($live, "run after rotation\n", FILE_APPEND);

        $this->assertSame($existing, file_get_contents($this->path('schedule-2026-08-20.log')));
        $this->assertSame("run after rotation\n", file_get_contents($live));
    }

    public function test_rotation_target_collision_gets_a_unique_suffix(): void
    {
        $this->writeLog('laravel-2026-08-20.log', 64);
        $this->writeLog('laravel.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        // The Monolog-owned file for today must survive untouched.
        $this->assertSame(64, filesize($this->path('laravel-2026-08-20.log')));
        $this->assertFileExists($this->path('laravel-2026-08-20-1.log'));
        $this->assertFileDoesNotExist($this->path('laravel.log'));
    }

    public function test_stale_log_files_are_pruned(): void
    {
        $stray = $this->writeLog('horizon.log', 64, ageInDays: 30);
        $oldRotation = $this->writeLog('schedule-2026-07-01.log', 64, ageInDays: 50);
        $recent = $this->writeLog('nzb_upload-2026-08-19.log', 64, ageInDays: 1);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($stray);
        $this->assertFileDoesNotExist($oldRotation);
        $this->assertFileExists($recent);
    }

    public function test_actively_appended_file_is_bounded_by_rotation_then_age_prune(): void
    {
        // A file that never goes idle always has a fresh mtime, so an age prune alone
        // would never bound it: it has to be rotated by size first.
        $this->writeLog('schedule.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $rotated = $this->path('schedule-2026-08-20.log');
        $this->assertFileExists($rotated);

        touch($rotated, Carbon::now()->subDays(30)->getTimestamp());
        $live = $this->writeLog('schedule.log', 64);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($rotated);
        $this->assertFileExists($live);
    }

    public function test_prune_window_follows_the_daily_channel_retention_setting(): void
    {
        config(['logging.channels.daily.days' => 30]);
        $stray = $this->writeLog('horizon.log', 64, ageInDays: 10);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($stray);

        config(['logging.channels.daily.days' => 7]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($stray);
    }

    public function test_monolog_rotated_daily_file_within_the_window_is_kept(): void
    {
        $kept = $this->writeLog('laravel-2026-08-14.log', 64, ageInDays: 6);
        $expired = $this->writeLog('laravel-2026-08-12.log', 64, ageInDays: 8);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($kept);
        $this->assertFileDoesNotExist($expired);
    }

    public function test_monolog_dated_files_are_never_rotated(): void
    {
        $managed = $this->writeLog('laravel-2026-08-20.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($managed);
        $this->assertFileDoesNotExist($this->path('laravel-2026-08-20-2026-08-20.log'));
    }

    public function test_rotation_never_claims_a_name_monolog_will_write(): void
    {
        // `laravel.log` is the daily channel's configured path, so the daily driver
        // owns `laravel-<date>.log` even on a day it has not opened it yet. Handing
        // it a `single`-driver leftover would make Monolog append to it.
        $this->writeLog('laravel.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($this->path('laravel-2026-08-20.log'));
        $this->assertFileExists($this->path('laravel-2026-08-20-1.log'));
    }

    public function test_previously_rotated_stray_is_rotated_again_rather_than_exempt(): void
    {
        // Nothing this service creates may become permanently exempt: `schedule` is
        // not a daily channel, so its dated file is still this service's to bound.
        $this->writeLog('schedule-2026-08-19.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileDoesNotExist($this->path('schedule-2026-08-19.log'));
        $this->assertFileExists($this->path('schedule-2026-08-20.log'));
    }

    public function test_zero_retention_keeps_every_file_like_monolog(): void
    {
        // Monolog reads `days => 0` as keep-forever, so the prune has to agree or
        // it would delete dated files the daily driver means to keep.
        config(['logging.channels.daily.days' => 0]);
        $stray = $this->writeLog('horizon.log', 64, ageInDays: 400);

        $this->artisan('logs:prune')
            ->expectsOutputToContain('age pruning disabled')
            ->assertSuccessful();

        $this->assertFileExists($stray);
    }

    public function test_zero_threshold_disables_rotation(): void
    {
        config(['nntmux.log_retention.rotate_size_mb' => 0]);
        $live = $this->writeLog('schedule.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($live);
        $this->assertFileDoesNotExist($this->path('schedule-2026-08-20.log'));
    }

    public function test_subdirectories_and_non_log_files_are_untouched(): void
    {
        $subdirectory = $this->logsDirectory.DIRECTORY_SEPARATOR.'archive';
        mkdir($subdirectory);
        $nested = $subdirectory.DIRECTORY_SEPARATOR.'ancient.log';
        file_put_contents($nested, str_repeat('x', 2 * 1024 * 1024));
        touch($nested, Carbon::now()->subDays(90)->getTimestamp());

        $notes = $this->writeLog('notes.txt', 2 * 1024 * 1024, ageInDays: 90);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertDirectoryExists($subdirectory);
        $this->assertFileExists($nested);
        $this->assertFileExists($notes);
    }

    public function test_rotation_threshold_is_configurable(): void
    {
        config(['nntmux.log_retention.rotate_size_mb' => 5]);
        $live = $this->writeLog('schedule.log', 2 * 1024 * 1024);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertFileExists($live);
    }

    public function test_dry_run_reports_without_touching_files(): void
    {
        $live = $this->writeLog('schedule.log', 2 * 1024 * 1024);
        $stray = $this->writeLog('horizon.log', 64, ageInDays: 30);

        $this->artisan('logs:prune --dry-run')
            ->expectsOutputToContain('schedule.log')
            ->expectsOutputToContain('horizon.log')
            ->assertSuccessful();

        $this->assertFileExists($live);
        $this->assertFileExists($stray);
        $this->assertFileDoesNotExist($this->path('schedule-2026-08-20.log'));
    }

    public function test_command_is_registered_on_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('logs:prune')
            ->assertSuccessful();
    }

    private function path(string $name): string
    {
        return $this->logsDirectory.DIRECTORY_SEPARATOR.$name;
    }

    private function writeLog(string $name, int $bytes, ?int $ageInDays = null): string
    {
        $path = $this->path($name);
        file_put_contents($path, str_repeat('x', $bytes));

        if ($ageInDays !== null) {
            touch($path, Carbon::now()->subDays($ageInDays)->getTimestamp());
        }

        return $path;
    }
}
