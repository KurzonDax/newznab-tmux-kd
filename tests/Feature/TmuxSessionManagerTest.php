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
        bool $configExists,
    ): void {
        $configFile = $configExists
            ? config_path('tmux.conf')
            : $this->makeTempPath('missing-tmux', '.conf');
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
            $configExists,
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
                    $remainOnExit = $configExists && in_array('-f', $command, true);
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
        $this->assertCount(3, $commands);
        $this->assertSame(['tmux', 'has-session', '-t', 'test-session'], $commands[0]);

        $newSessionCommand = $commands[1];
        $this->assertIsArray($newSessionCommand);
        $this->assertContains('new-session', $newSessionCommand);
        $this->assertSame('sh', end($newSessionCommand));

        if ($configExists) {
            $configFlagIndex = array_search('-f', $newSessionCommand, true);
            $this->assertIsInt($configFlagIndex);
            $this->assertSame($configFile, $newSessionCommand[$configFlagIndex + 1]);
        } else {
            $this->assertNotContains('-f', $newSessionCommand);
        }

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
        ], $commands[2]);
    }

    public static function serverScenarios(): array
    {
        return [
            'existing server ignores the new-session config flag' => [true, true],
            'fresh server loads the config flag' => [false, true],
            'fresh server without a config uses the fallback' => [false, false],
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
}
