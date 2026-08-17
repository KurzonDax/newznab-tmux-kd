<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TmuxPaneRole;
use App\Services\Tmux\TmuxLayoutBuilder;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TmuxPaneManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
    }

    public function test_roles_resolve_to_stable_pane_ids(): void
    {
        Process::fake([
            '*' => Process::result("%12\tmonitor\n%27\tpost_movies\n"),
        ]);

        $manager = new TmuxPaneManager('test session');

        $this->assertSame('%12', $manager->paneForRole(TmuxPaneRole::Monitor));
        $this->assertSame('%27', $manager->paneForRole(TmuxPaneRole::PostMovies));

        Process::assertRanTimes(
            fn (PendingProcess $process): bool => is_array($process->command)
                && in_array('list-panes', $process->command, true),
            1,
        );
    }

    public function test_duplicate_roles_are_rejected(): void
    {
        Process::fake([
            '*' => Process::result("%12\tmonitor\n%27\tmonitor\n"),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("role 'monitor' is assigned more than once");

        (new TmuxPaneManager('test-session'))->paneForRole(TmuxPaneRole::Monitor);
    }

    public function test_missing_roles_are_rejected(): void
    {
        Process::fake([
            '*' => Process::result("%12\tmonitor\n"),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("role 'post_movies' was not found");

        (new TmuxPaneManager('test-session'))->paneForRole(TmuxPaneRole::PostMovies);
    }

    public function test_legacy_coordinate_is_tagged_during_role_resolution(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%8\t\n");
            }

            if (is_array($process->command) && in_array('display-message', $process->command, true)) {
                return Process::result("%8\n");
            }

            return Process::result();
        });

        $manager = new TmuxPaneManager('legacy-session');

        $this->assertSame('%8', $manager->paneForRole(TmuxPaneRole::Monitor, '0.0'));

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === [
                'tmux',
                'set-option',
                '-p',
                '-t',
                '%8',
                '@nntmux_role',
                'monitor',
            ];
        });
    }

    public function test_respawn_passes_the_command_as_one_argument(): void
    {
        Process::fake();

        $command = <<<'SH'
echo "$HOME" && printf '%s\n' 'quoted value'
SH;

        $this->assertTrue((new TmuxPaneManager('test session'))->respawnPane('%42', $command, kill: true));

        Process::assertRan(function (PendingProcess $process) use ($command): bool {
            return $process->command === [
                'tmux',
                'respawn-pane',
                '-k',
                '-t',
                '%42',
                $command,
            ];
        });
    }

    #[DataProvider('layoutRolesProvider')]
    public function test_layouts_tag_every_logical_pane(int $mode, array $expectedRoles): void
    {
        $nextPaneId = 1;
        $assignedRoles = [];
        $splitTargets = [];

        Process::fake(function (PendingProcess $process) use (&$nextPaneId, &$assignedRoles, &$splitTargets) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result();
            }

            if (in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }

            if (in_array('new-session', $command, true)
                || in_array('new-window', $command, true)
                || in_array('split-window', $command, true)) {
                if (in_array('split-window', $command, true)) {
                    $splitTargets[] = $command[array_search('-t', $command, true) + 1];
                }

                return Process::result('%'.$nextPaneId++."\n");
            }

            $roleOptionIndex = array_search('@nntmux_role', $command, true);
            if ($roleOptionIndex !== false) {
                $assignedRoles[] = $command[$roleOptionIndex + 1];
            }

            return Process::result();
        });

        $sessionManager = new TmuxSessionManager('test-session');
        $layoutBuilder = new class($sessionManager) extends TmuxLayoutBuilder
        {
            protected function createOptionalWindows(): void {}
        };

        $this->assertTrue($layoutBuilder->buildLayout($mode), (string) $layoutBuilder->lastError());
        $this->assertEqualsCanonicalizing($expectedRoles, $assignedRoles);

        foreach ($splitTargets as $target) {
            $this->assertStringStartsWith('%', $target);
        }
    }

    public static function layoutRolesProvider(): array
    {
        return [
            'full' => [
                0,
                [
                    'monitor',
                    'binaries',
                    'backfill',
                    'releases',
                    'fix_names',
                    'remove_crap',
                    'post_additional',
                    'post_movies',
                    'post_tv',
                    'post_metadata',
                    'irc_scraper',
                ],
            ],
            'basic' => [
                1,
                [
                    'monitor',
                    'releases',
                    'fix_names',
                    'remove_crap',
                    'post_additional',
                    'post_movies',
                    'post_tv',
                    'post_metadata',
                    'irc_scraper',
                ],
            ],
            'stripped' => [
                2,
                [
                    'monitor',
                    'sequential',
                    'fix_names',
                    'post_metadata',
                    'irc_scraper',
                ],
            ],
        ];
    }

    public function test_partial_layout_is_removed_after_a_split_failure(): void
    {
        $sessionExists = false;
        $splitAttempts = 0;

        Process::fake(function (PendingProcess $process) use (&$sessionExists, &$splitAttempts) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result();
            }

            if (in_array('has-session', $command, true)) {
                return Process::result('', '', $sessionExists ? 0 : 1);
            }

            if (in_array('new-session', $command, true)) {
                $sessionExists = true;

                return Process::result("%1\n");
            }

            if (in_array('split-window', $command, true)) {
                $splitAttempts++;
                if ($splitAttempts === 2) {
                    return Process::result('', 'split failed', 1);
                }

                return Process::result("%2\n");
            }

            if (in_array('kill-session', $command, true)) {
                $sessionExists = false;
            }

            return Process::result();
        });

        $sessionManager = new TmuxSessionManager('test-session');
        $layoutBuilder = new class($sessionManager) extends TmuxLayoutBuilder
        {
            protected function createOptionalWindows(): void {}
        };

        $this->assertFalse($layoutBuilder->buildLayout(0));
        $this->assertSame('split failed', $layoutBuilder->lastError());
        $this->assertFalse($sessionExists);

        Process::assertRan(
            fn (PendingProcess $process): bool => is_array($process->command)
                && in_array('kill-session', $process->command, true),
        );
    }

    public function test_task_runner_resolves_a_role_before_respawning(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tpost_movies\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');

        $this->assertTrue($runner->runTask('Movies', [
            'role' => TmuxPaneRole::PostMovies,
            'command' => 'php artisan example',
        ]));

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === [
                'tmux',
                'respawn-pane',
                '-t',
                '%9',
                'php artisan example',
            ];
        });
    }

    public function test_backfill_mode_one_runs_the_single_multiprocessing_command(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tbackfill\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');

        $this->assertTrue($runner->runBackfill([
            'settings' => ['backfill' => 1, 'back_timer' => 30, 'progressive' => 0],
            'killswitch' => ['coll' => false, 'pp' => false],
            'counts' => ['now' => ['collections_table' => 0]],
        ]));

        Process::assertRan(function (PendingProcess $process): bool {
            if (! is_array($process->command) || ! in_array('respawn-pane', $process->command, true)) {
                return false;
            }

            $command = end($process->command);

            return is_string($command)
                && str_contains($command, 'multiprocessing:backfill')
                && ! str_contains($command, 'multiprocessing:safe backfill');
        });
    }

    public function test_backfill_mode_zero_disables_the_pane_and_legacy_mode_four_is_not_runnable(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tbackfill\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');
        $baseConfig = [
            'killswitch' => ['coll' => false, 'pp' => false],
            'counts' => ['now' => ['collections_table' => 0]],
        ];

        $this->assertTrue($runner->runBackfill($baseConfig + ['settings' => ['backfill' => 0]]));
        $this->assertFalse($runner->runBackfill($baseConfig + ['settings' => ['backfill' => 4]]));

        Process::assertRan(function (PendingProcess $process): bool {
            return is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && in_array('-k', $process->command, true)
                && str_contains((string) end($process->command), 'Backfill has been disabled');
        });
        Process::assertDidntRun(function (PendingProcess $process): bool {
            return is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && str_contains((string) end($process->command), 'multiprocessing:safe backfill');
        });
    }

    #[DataProvider('srrdbFixNameLevelProvider')]
    public function test_fix_names_task_only_includes_srrdb_level_when_enabled(bool $enabled): void
    {
        config(['nntmux_srrdb.enabled' => $enabled]);
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tfix_names\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');
        $this->assertTrue($runner->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300],
            'counts' => ['now' => ['processrenames' => 1]],
        ]));

        Process::assertRan(function (PendingProcess $process) use ($enabled): bool {
            if (! is_array($process->command) || ! in_array('respawn-pane', $process->command, true)) {
                return false;
            }

            $command = end($process->command);

            return is_string($command)
                && str_contains($command, 'releases:fix-names 19')
                && str_contains($command, 'releases:fix-names 21') === $enabled;
        });
    }

    public static function srrdbFixNameLevelProvider(): array
    {
        return [
            'disabled' => [false],
            'enabled' => [true],
        ];
    }
}
