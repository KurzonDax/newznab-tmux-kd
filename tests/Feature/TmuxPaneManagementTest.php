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
use Symfony\Component\Process\ExecutableFinder;
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
            'a legacy stripped value falls back to full' => [
                2,
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
        ];
    }

    #[DataProvider('mainTaskSequentialModeProvider')]
    public function test_main_task_dispatch_treats_any_non_basic_mode_as_full(int $sequential, bool $expectBinaries): void
    {
        $respawned = [];
        Process::fake(function (PendingProcess $process) use (&$respawned) {
            if (! is_array($process->command)) {
                return Process::result();
            }

            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\tbinaries\n%2\tbackfill\n%3\treleases\n");
            }

            if (in_array('respawn-pane', $process->command, true)) {
                $respawned[] = (string) end($process->command);
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');

        $this->assertTrue($runner->runPaneTask('main', [], [
            'constants' => ['sequential' => $sequential],
            'settings' => ['binaries_run' => 1, 'backfill' => 1, 'releases_run' => 1, 'progressive' => 0],
            'killswitch' => ['coll' => false, 'pp' => false],
            'counts' => ['now' => ['collections_table' => 0]],
        ]));

        $contains = static fn (string $needle): bool => count(array_filter(
            $respawned,
            static fn (string $command): bool => str_contains($command, $needle),
        )) > 0;

        $this->assertSame($expectBinaries, $contains('multiprocessing:safe binaries'));
        $this->assertSame($expectBinaries, $contains('multiprocessing:backfill'));
        $this->assertTrue($contains('multiprocessing:releases'), 'releases run in every mode');
        $this->assertFalse($contains('group:update-all'), 'the retired sequential task is gone');
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function mainTaskSequentialModeProvider(): array
    {
        return [
            'full' => [0, true],
            'basic' => [1, false],
            'legacy stripped' => [2, true],
            'unknown' => [7, true],
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

    #[DataProvider('emptyProcessingPaneProvider')]
    public function test_processing_pane_parks_when_its_candidate_backlogs_are_empty(
        string $taskName,
        string $paneRole,
        array $settings,
        array $counts,
        string $displayName,
        string $reason,
        string $forbiddenCommand,
    ): void {
        Process::fake(function (PendingProcess $process) use ($paneRole) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\t{$paneRole}\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');

        $this->assertTrue($runner->runPaneTask($taskName, [], [
            'settings' => $settings,
            'constants' => ['sequential' => 0],
            'counts' => ['now' => $counts],
        ]));

        Process::assertRan(function (PendingProcess $process) use ($displayName, $reason): bool {
            return is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && in_array('-k', $process->command, true)
                && str_contains((string) end($process->command), "{$displayName} has been disabled")
                && str_contains((string) end($process->command), $reason);
        });
        Process::assertDidntRun(function (PendingProcess $process) use ($forbiddenCommand): bool {
            return is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && str_contains((string) end($process->command), $forbiddenCommand);
        });
    }

    /**
     * @return array<string, array{string, string, array<string, int>, array<string, int>, string, string, string}>
     */
    public static function emptyProcessingPaneProvider(): array
    {
        return [
            'books, music, and games' => [
                'amazon',
                'post_metadata',
                ['post_amazon' => 1],
                [
                    'processmusic' => 0,
                    'processbooks' => 0,
                    'processconsole' => 0,
                    'processgames' => 0,
                    'audio_work_available' => 0,
                ],
                'Post-process Metadata',
                'no music/books/games or audio previews to process',
                'multiprocessing:postprocess ama',
            ],
            'TV and anime' => [
                'tv',
                'post_tv',
                ['post_non' => 1, 'processtvrage' => 1, 'processanime' => 1],
                ['processtv' => 0, 'processanime' => 0],
                'Post-process TV/Anime',
                'no work for enabled types (TV, Anime)',
                'multiprocessing:postprocess tv',
            ],
            'movies' => [
                'movies',
                'post_movies',
                ['post_non' => 1, 'processmovies' => 1],
                ['processmovies' => 0],
                'Post-process Movies',
                'no work available',
                'multiprocessing:postprocess mov',
            ],
            'NFO' => [
                'ppadditional',
                'post_additional',
                ['post' => 2],
                ['processnfo' => 0, 'work_available' => 0],
                'Post-process Additional',
                'no NFOs to process',
                'multiprocessing:postprocess nfo',
            ],
        ];
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

    public function test_fix_names_chain_bounds_every_step_and_ends_with_standard_then_predb_sweeps(): void
    {
        config(['nntmux_srrdb.enabled' => false]);
        $this->fakeFixNamesPane();

        $runner = $this->runnerWithTimeoutBinary('/usr/bin/timeout');
        $this->assertTrue($runner->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300, 'fix_names_timeout' => 900],
            'counts' => ['now' => ['processrenames' => 1]],
        ]));

        $command = $this->respawnedCommand();

        $expectedOrder = [];
        foreach ([3, 5, 7, 9, 11, 13, 15, 17, 19] as $level) {
            $expectedOrder[] = '/usr/bin/timeout -k 10 900 php ';
            $expectedOrder[] = "releases:fix-names {$level} --update --category=other --set-status --show";
            $expectedOrder[] = "fix-names {$level} timed out after 900s";
        }
        $expectedOrder[] = '/usr/bin/timeout -k 10 900 php ';
        $expectedOrder[] = 'multiprocessing:fixrelnames standard';
        $expectedOrder[] = 'fix-names standard sweep timed out after 900s';
        $expectedOrder[] = '/usr/bin/timeout -k 10 900 php ';
        $expectedOrder[] = 'multiprocessing:fixrelnames predbft';
        $expectedOrder[] = 'fix-names predb full-text sweep timed out after 900s';
        $expectedOrder[] = "date +'%Y-%m-%d %T'";

        $this->assertAppearInOrder($expectedOrder, $command);
        $this->assertSame(11, substr_count($command, '| tee -a '), 'every step logs to the pane log');
        $this->assertSame(11, substr_count($command, '/usr/bin/timeout -k 10 900 '), 'every step is bounded');
    }

    public function test_fix_names_task_wakes_for_predb_only_work(): void
    {
        $this->fakeFixNamesPane();

        $this->assertTrue($this->runnerWithTimeoutBinary('timeout')->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300],
            'counts' => ['now' => ['processrenames' => 0, 'processpredbft' => 1]],
        ]));

        $this->assertStringContainsString('multiprocessing:fixrelnames predbft', $this->respawnedCommand());
    }

    #[DataProvider('fixNamesTimeoutProvider')]
    public function test_fix_names_timeout_defaults_and_is_floored_at_sixty_seconds(?int $configured, int $expected): void
    {
        $this->fakeFixNamesPane();

        $settings = ['fix_names' => 1, 'fix_timer' => 300];
        if ($configured !== null) {
            $settings['fix_names_timeout'] = $configured;
        }

        $this->runnerWithTimeoutBinary('timeout')->runPaneTask('fixnames', [], [
            'settings' => $settings,
            'counts' => ['now' => ['processrenames' => 1]],
        ]);

        $this->assertStringContainsString("timeout -k 10 {$expected} php", $this->respawnedCommand());
    }

    /**
     * @return array<string, array{?int, int}>
     */
    public static function fixNamesTimeoutProvider(): array
    {
        return [
            'missing setting uses the default' => [null, TmuxTaskRunner::DEFAULT_FIX_NAMES_TIMEOUT],
            'below the floor is raised to it' => [5, TmuxTaskRunner::MIN_FIX_NAMES_TIMEOUT],
            'configured value is used' => [900, 900],
        ];
    }

    public function test_fix_names_chain_runs_unbounded_when_the_host_has_no_timeout_binary(): void
    {
        $this->fakeFixNamesPane();

        $this->runnerWithTimeoutBinary(null)->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300],
            'counts' => ['now' => ['processrenames' => 1]],
        ]);

        $command = $this->respawnedCommand();
        $this->assertStringNotContainsString('timeout -k', $command);
        $this->assertStringNotContainsString('timed out', $command);
        $this->assertStringStartsWith("echo 'fix_names_timeout is not enforced", $command);
        $this->assertStringContainsString('multiprocessing:fixrelnames standard 2>&1 | tee -a', $command);
    }

    /**
     * The generated step really does kill a hung command, write one notice, and
     * let the chain continue. Needs coreutils `timeout`; skipped where absent.
     */
    public function test_a_bounded_step_kills_a_hung_command_and_moves_on(): void
    {
        $timeout = (new ExecutableFinder)->find('timeout');
        if ($timeout === null) {
            $this->markTestSkipped('coreutils timeout is not installed on this host.');
        }

        $log = $this->makeTempPath('nntmux-fixnames', '.log');
        $runner = new TmuxTaskRunner('test-session');
        $chain = $runner->boundedStep('fix-names 7', 'sleep 30', 1, $log).'; echo next-step';

        $started = microtime(true);
        $process = \Symfony\Component\Process\Process::fromShellCommandline($chain);
        $process->setTimeout(20);
        $process->run();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(15, $elapsed, 'the hung step must be cut off at the limit');
        $this->assertMatchesRegularExpression('/fix-names 7 timed out after 1s, moving on\n.*next-step/s', $process->getOutput());
        $this->assertSame(1, substr_count((string) file_get_contents($log), 'timed out after 1s'));
        $this->assertStringNotContainsString('next-step', (string) file_get_contents($log), 'only the step itself is logged');
    }

    private function fakeFixNamesPane(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tfix_names\n");
            }

            return Process::result();
        });
    }

    private function runnerWithTimeoutBinary(?string $binary): TmuxTaskRunner
    {
        return new class('test-session', $binary) extends TmuxTaskRunner
        {
            public function __construct(string $sessionName, private readonly ?string $binary)
            {
                parent::__construct($sessionName);
            }

            protected function timeoutBinary(): ?string
            {
                return $this->binary;
            }
        };
    }

    private function respawnedCommand(): string
    {
        $command = null;
        Process::assertRan(function (PendingProcess $process) use (&$command): bool {
            if (! is_array($process->command) || ! in_array('respawn-pane', $process->command, true)) {
                return false;
            }

            $command = (string) end($process->command);

            return true;
        });

        $this->assertIsString($command);

        return $command;
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertAppearInOrder(array $needles, string $haystack): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle, $offset);
            $this->assertNotFalse($position, "Expected to find [{$needle}] after offset {$offset} in:\n{$haystack}");
            $offset = $position + strlen($needle);
        }
    }
}
