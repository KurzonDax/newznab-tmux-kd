<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

class TmuxHealthCheckCommandTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = $this->makeTempPath('nntmux-tmux-health-check-test', '.sqlite');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec('CREATE TABLE collections (id INTEGER PRIMARY KEY AUTOINCREMENT, dateadded TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('running', '0'),
            ('sequential', '0'),
            ('delaytime', '2'),
            ('monitor_delay', '0'),
            ('tmux_session', 'test-session'),
            ('backup_pause_marker', '')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_missing_session_succeeds_when_engine_is_stopped(): void
    {
        $this->fakeMissingSession();

        $this->artisan('tmux:health-check --auto-restart --session=test-session')
            ->expectsOutputToContain("Tmux session 'test-session' does not exist.")
            ->assertExitCode(0);

        Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isTmuxCommand($process, 'has-session'), 1);
        Process::assertNotRan('which tmux 2>/dev/null');
    }

    public function test_missing_session_fails_without_auto_restart_when_engine_should_be_running(): void
    {
        $this->setSetting('running', '1');
        $this->fakeMissingSession();

        $this->artisan('tmux:health-check --session=test-session')
            ->expectsOutputToContain("Tmux session 'test-session' does not exist.")
            ->assertExitCode(1);

        Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isTmuxCommand($process, 'has-session'), 1);
        Process::assertNotRan('which tmux 2>/dev/null');
    }

    public function test_missing_session_auto_restarts_when_engine_should_be_running(): void
    {
        $this->setSetting('running', '1');
        $this->fakeMissingSessionWithSuccessfulStart();

        $this->artisan('tmux:health-check --auto-restart --session=test-session')
            ->expectsOutputToContain("Tmux session 'test-session' does not exist.")
            ->expectsOutputToContain("Tmux session 'test-session' restarted successfully.")
            ->assertExitCode(0);

        Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isTmuxCommand($process, 'has-session'), 4);
        Process::assertRan('which tmux 2>/dev/null');
        Process::assertRan(fn (PendingProcess $process): bool => $this->isTmuxCommand($process, 'new-session'));
    }

    public function test_stale_backup_pause_is_recovered_before_health_check(): void
    {
        $this->setSetting('running', '0');
        $this->setSetting('backup_pause_marker', json_encode([
            'paused_at' => Carbon::now()->subHours(3)->toIso8601String(),
            'prior_running' => true,
        ], JSON_THROW_ON_ERROR));
        $this->fakeMissingSession();

        $this->artisan('tmux:health-check --session=test-session')
            ->expectsOutputToContain('Recovered stale database Backup pause')
            ->assertFailed();

        $this->assertSame('1', $this->app['db']->table('settings')->where('name', 'running')->value('value'));
        $this->assertSame('', $this->app['db']->table('settings')->where('name', 'backup_pause_marker')->value('value'));
    }

    public function test_stale_marker_is_not_recovered_while_recorded_process_is_alive(): void
    {
        $marker = json_encode([
            'paused_at' => Carbon::now()->subHours(3)->toIso8601String(),
            'prior_running' => true,
            'pid' => getmypid(),
        ], JSON_THROW_ON_ERROR);
        $this->setSetting('running', '0');
        $this->setSetting('backup_pause_marker', $marker);
        $this->fakeMissingSession();

        $this->artisan('tmux:health-check --session=test-session')->assertSuccessful();

        $this->assertSame('0', $this->app['db']->table('settings')->where('name', 'running')->value('value'));
        $this->assertSame($marker, $this->app['db']->table('settings')->where('name', 'backup_pause_marker')->value('value'));
    }

    public function test_stale_dead_process_clears_orphaned_backup_lock_and_recovers_pause(): void
    {
        $orphanedLock = Cache::lock('database-backup-run', 90000);
        $this->assertTrue($orphanedLock->get());
        $this->setSetting('running', '0');
        $this->setSetting('backup_pause_marker', json_encode([
            'paused_at' => Carbon::now()->subHours(3)->toIso8601String(),
            'prior_running' => true,
            'pid' => 999999999,
            'process_start_ticks' => 'dead-process',
            'lock_owner' => $orphanedLock->owner(),
        ], JSON_THROW_ON_ERROR));
        $this->fakeMissingSession();

        $this->artisan('tmux:health-check --session=test-session')
            ->expectsOutputToContain('Recovered stale database Backup pause')
            ->assertFailed();

        $this->assertSame('1', $this->app['db']->table('settings')->where('name', 'running')->value('value'));
        $this->assertSame('', $this->app['db']->table('settings')->where('name', 'backup_pause_marker')->value('value'));
        $replacementLock = Cache::lock('database-backup-run', 1);
        $this->assertTrue($replacementLock->get());
        $replacementLock->release();
    }

    public function test_stale_marker_cannot_release_a_new_backup_owners_lock(): void
    {
        $newLock = Cache::lock('database-backup-run', 90000);
        $this->assertTrue($newLock->get());
        $marker = json_encode([
            'paused_at' => Carbon::now()->subHours(3)->toIso8601String(),
            'prior_running' => true,
            'pid' => 999999999,
            'process_start_ticks' => 'dead-process',
            'lock_owner' => 'old-backup-owner',
        ], JSON_THROW_ON_ERROR);
        $this->setSetting('running', '0');
        $this->setSetting('backup_pause_marker', $marker);
        $this->fakeMissingSession();

        try {
            $this->artisan('tmux:health-check --session=test-session')->assertSuccessful();

            $this->assertSame('0', $this->app['db']->table('settings')->where('name', 'running')->value('value'));
            $this->assertSame($marker, $this->app['db']->table('settings')->where('name', 'backup_pause_marker')->value('value'));
            $probeLock = Cache::lock('database-backup-run', 1);
            $this->assertFalse($probeLock->get());
        } finally {
            $newLock->release();
        }
    }

    private function fakeMissingSession(): void
    {
        Process::fake(fn () => Process::result('', '', 1));
    }

    private function fakeMissingSessionWithSuccessfulStart(): void
    {
        $nextPaneId = 1;

        Process::fake(function (PendingProcess $process) use (&$nextPaneId) {
            if ($process->command === 'which tmux 2>/dev/null') {
                return Process::result('/usr/bin/tmux'.PHP_EOL);
            }

            if (! is_array($process->command)) {
                return Process::result();
            }

            if (in_array('has-session', $process->command, true)) {
                return Process::result('', '', 1);
            }

            if (in_array('new-session', $process->command, true)
                || in_array('new-window', $process->command, true)
                || in_array('split-window', $process->command, true)) {
                return Process::result('%'.$nextPaneId++."\n");
            }

            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\tmonitor\n");
            }

            return Process::result();
        });
    }

    private function isTmuxCommand(PendingProcess $process, string $command): bool
    {
        return is_array($process->command) && in_array($command, $process->command, true);
    }

    private function setSetting(string $name, string $value): void
    {
        $this->app['db']->table('settings')->where('name', $name)->update(['value' => $value]);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
