<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\TmuxSessionManager;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TmuxSessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
    }

    #[DataProvider('serverScenarios')]
    public function test_session_creation_enforces_remain_on_exit_before_using_a_dead_placeholder(
        bool $serverAlreadyRunning,
        bool $customConfig,
    ): void {
        $configFile = $customConfig
            ? $this->makeTempPath('custom tmux profile', '.conf')
            : config_path('tmux.conf');
        if ($customConfig) {
            file_put_contents($configFile, "set -g mouse on\n");
        }
        config(['tmux.config_file' => $configFile]);
        $serverRunning = $serverAlreadyRunning;
        $targetSessionExists = false;
        $remainOnExit = false;
        $paneAlive = false;
        $commands = [];

        Process::fake(function (PendingProcess $process) use (
            &$commands,
            &$serverRunning,
            &$targetSessionExists,
            &$remainOnExit,
            &$paneAlive,
            $customConfig,
        ) {
            $command = $process->command;
            $commands[] = $command;

            if (! is_array($command)) {
                return Process::result();
            }

            if (in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }

            if (in_array('new-session', $command, true)) {
                if (! $serverRunning) {
                    $serverRunning = true;
                    $remainOnExit = ! $customConfig && in_array('-f', $command, true);
                }

                $targetSessionExists = true;
                $paneAlive = end($command) === 'sh';

                return Process::result("%1\n");
            }

            if (in_array('set-option', $command, true)) {
                if (! $targetSessionExists || ! $paneAlive) {
                    return Process::result('', 'initial pane exited before server options were applied', 1);
                }

                $remainOnExit = true;
                $paneAlive = ! in_array('respawn-pane', $command, true);
            }

            return Process::result();
        });

        $paneId = (new TmuxSessionManager('test-session'))->createSession();

        $this->assertSame('%1', $paneId);
        $this->assertTrue($serverRunning);
        $this->assertTrue($targetSessionExists);
        $this->assertTrue($remainOnExit);
        $this->assertFalse($paneAlive);
        $this->assertCount(4, $commands);
        $this->assertSame(['tmux', 'has-session', '-t', 'test-session'], $commands[0]);

        $newSessionCommand = $commands[1];
        $this->assertIsArray($newSessionCommand);
        $this->assertContains('new-session', $newSessionCommand);
        $this->assertSame('sh', end($newSessionCommand));

        $this->assertSame(['tmux', 'source-file', $configFile], $commands[2]);

        $this->assertSame([
            'tmux',
            'set-option',
            '-g',
            'remain-on-exit',
            'on',
            ';',
            'respawn-pane',
            '-k',
            '-t',
            '%1',
            'true',
        ], $commands[3]);
    }

    public static function serverScenarios(): array
    {
        return [
            'existing server with repository profile' => [true, false],
            'fresh server with repository profile' => [false, false],
            'existing server with custom profile' => [true, true],
            'fresh server with custom profile' => [false, true],
        ];
    }

    public function test_session_creation_reports_server_option_enforcement_failure(): void
    {
        config(['tmux.config_file' => config_path('tmux.conf')]);
        $commands = [];

        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            $commands[] = $command;

            if (is_array($command) && in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }

            if (is_array($command) && in_array('new-session', $command, true)) {
                return Process::result("%1\n");
            }

            if (is_array($command) && in_array('set-option', $command, true)) {
                return Process::result('', 'unable to set server options', 1);
            }

            return Process::result();
        });

        $manager = new TmuxSessionManager('test-session');

        $this->assertNull($manager->createSession());
        $this->assertSame('unable to set server options', $manager->lastError());
        $this->assertSame(['tmux', 'kill-session', '-t', 'test-session'], end($commands));
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_profile_fails_before_creating_a_session(string $kind): void
    {
        $profile = $this->makeTempPath('invalid profile', '.conf');
        if ($kind === 'unreadable') {
            file_put_contents($profile, "set -g mouse on\n");
            chmod($profile, 0000);
        } elseif ($kind === 'directory') {
            mkdir($profile);
        }
        config(['tmux.config_file' => $profile]);
        Process::fake(fn () => Process::result('', '', 1));

        try {
            $manager = new TmuxSessionManager('test-session');
            $this->assertNull($manager->createSession());
            $this->assertStringContainsString($profile, (string) $manager->lastError());
            $this->assertStringContainsString('readable file', (string) $manager->lastError());
            Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
                && (in_array('new-session', $process->command, true) || in_array('kill-session', $process->command, true)));
        } finally {
            if ($kind === 'unreadable') {
                chmod($profile, 0600);
            }
        }
    }

    public static function invalidProfiles(): array
    {
        return ['missing' => ['missing'], 'unreadable' => ['unreadable'], 'directory' => ['directory']];
    }
}
