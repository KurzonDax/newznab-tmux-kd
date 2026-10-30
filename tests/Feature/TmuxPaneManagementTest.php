<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TmuxMonitor;
use App\Enums\TmuxPaneRole;
use App\Services\Tmux\Tmux;
use App\Services\Tmux\TmuxLayoutBuilder;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxOutput;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxPaneSupervisor;
use App\Services\Tmux\TmuxSessionManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class TmuxPaneManagementTest extends TestCase
{
    /** @var list<string> commands sent through `respawn-pane` while fakePaneRole() is active */
    private array $respawned = [];

    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses();
        // Pane status files and the supervision state live under storage/tmux.
        $this->app->useStoragePath($this->makeTempDirectory('nntmux-storage'));
    }

    public function test_engine_start_does_not_rewrite_collection_timestamps(): void
    {
        $this->seedMonitoringSettings(['delaytime' => '2', 'sequential' => '0']);
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('dateadded');
        });
        DB::table('collections')->insert(['id' => 1, 'dateadded' => '2026-01-01 00:00:00']);
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'collections')) {
                $updates[] = $query->sql;
            }
        });
        Process::fake(function (PendingProcess $process) {
            if (is_string($process->command) && str_contains($process->command, 'which tmux')) {
                return Process::result('/usr/bin/tmux');
            }

            // Stop at layout creation, after the historical restart reset.
            return Process::result('', '', 1);
        });

        $this->artisan('tmux:start', ['--session' => 'test-no-reset'])->assertFailed();

        $this->assertSame([], $updates);
        $this->assertDatabaseHas('collections', ['id' => 1, 'dateadded' => '2026-01-01 00:00:00']);
        $this->assertFalse((new TmuxMonitor)->getDefinition()->hasOption('reset-collections'));
    }

    #[DataProvider('profileFailures')]
    public function test_start_aborts_before_layout_and_processing_when_profile_loading_fails(bool $throws): void
    {
        $this->seedMonitoringSettings(['sequential' => '0', 'running' => '0']);
        $profile = $this->makeTempPath('tmux profile', '.conf');
        file_put_contents($profile, "set -g mouse on\n");
        config(['tmux.config_file' => $profile]);
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands, $throws) {
            $command = $process->command;
            $commands[] = $command;
            if (is_string($command) && str_contains($command, 'which tmux')) {
                return Process::result('/usr/bin/tmux');
            }
            if (is_array($command) && in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }
            if (is_array($command) && in_array('new-session', $command, true)) {
                return Process::result("%1\n");
            }
            if (is_array($command) && in_array('source-file', $command, true)) {
                if ($throws) {
                    throw new RuntimeException('profile process timed out');
                }

                return Process::result('', 'unknown tmux option', 1);
            }

            return Process::result();
        });

        $detail = $throws ? 'profile process timed out' : 'unknown tmux option';
        $this->artisan('tmux:start', ['--session' => 'test-profile'])
            ->expectsOutputToContain("Unable to load tmux profile '{$profile}': {$detail}")
            ->assertFailed();

        $this->assertDatabaseHas('settings', ['name' => 'running', 'value' => '0']);
        $this->assertSame(['tmux', 'kill-session', '-t', '=test-profile'], end($commands));
        Process::assertNotRan(fn (PendingProcess $process): bool => is_array($process->command)
            && count(array_intersect(['split-window', 'new-window', 'respawn-pane', 'kill-server'], $process->command)) > 0);
    }

    /** @return array<string, array{bool}> */
    public static function profileFailures(): array
    {
        return ['nonzero source result' => [false], 'source exception' => [true]];
    }

    #[DataProvider('realProfileScenarios')]
    public function test_real_tmux_applies_profile_before_building_layout_and_preserves_unrelated_processes(
        bool $existingServer,
        bool $invalidProfile,
    ): void {
        $socket = 'nntmux-test-'.bin2hex(random_bytes(8));
        $profile = $this->makeTempPath('custom tmux profile', '.conf');
        file_put_contents($profile, $invalidProfile
            ? "not-a-tmux-command\n"
            : "set -g mouse on\nset -g history-limit 4321\nset -g prefix C-a\nbind-key F12 display-message profile-loaded\n");
        config(['tmux.config_file' => $profile]);

        try {
            $unrelatedProcess = null;
            if ($existingServer) {
                $start = $this->runIsolatedTmux($socket, ['-f', '/dev/null', 'new-session', '-d', '-s', 'unrelated', 'sleep 120']);
                $this->assertTrue($start->isSuccessful(), $start->getErrorOutput());
                $unrelatedProcess = $this->runIsolatedTmux($socket, ['display-message', '-p', '-t', 'unrelated', '#{pane_pid}:#{pane_dead}'])->getOutput();
                $this->assertSame("off\n", $this->runIsolatedTmux($socket, ['show-options', '-gv', 'mouse'])->getOutput());
            }

            Process::fake(function (PendingProcess $process) use ($socket) {
                $this->assertIsArray($process->command);
                $this->assertSame('tmux', $process->command[0]);
                $result = $this->runIsolatedTmux($socket, array_slice($process->command, 1));

                return Process::result($result->getOutput(), $result->getErrorOutput(), $result->getExitCode());
            });
            $builder = new class(new TmuxSessionManager('processing')) extends TmuxLayoutBuilder
            {
                protected function createOptionalWindows(): void {}
            };

            $this->assertSame(! $invalidProfile, $builder->buildLayout(0), (string) $builder->lastError());
            if ($invalidProfile) {
                $this->assertStringContainsString($profile, (string) $builder->lastError());
                $this->assertStringContainsString('unknown command', (string) $builder->lastError());
                $this->assertFalse($this->runIsolatedTmux($socket, ['has-session', '-t', '=processing'])->isSuccessful());
            } else {
                $this->assertSame("on\n", $this->runIsolatedTmux($socket, ['show-options', '-gv', 'mouse'])->getOutput());
                $this->assertSame("4321\n", $this->runIsolatedTmux($socket, ['show-options', '-gv', 'history-limit'])->getOutput());
                $this->assertSame("C-a\n", $this->runIsolatedTmux($socket, ['show-options', '-gv', 'prefix'])->getOutput());
                $this->assertStringContainsString('profile-loaded', $this->runIsolatedTmux($socket, ['list-keys', 'F12'])->getOutput());
                $panes = $this->runIsolatedTmux($socket, ['list-panes', '-s', '-t', '=processing', '-F', '#{@nntmux_role}:#{remain-on-exit}']);
                $this->assertEqualsCanonicalizing(
                    array_map(fn (string $role): string => $role.':on', self::layoutRolesProvider()['full'][1]),
                    explode("\n", trim($panes->getOutput())),
                );
            }

            if ($existingServer) {
                $this->assertSame($unrelatedProcess, $this->runIsolatedTmux($socket, ['display-message', '-p', '-t', 'unrelated', '#{pane_pid}:#{pane_dead}'])->getOutput());
                $this->assertStringEndsWith(":0\n", (string) $unrelatedProcess);
            }
        } finally {
            $this->runIsolatedTmux($socket, ['kill-server']);
        }
    }

    /** @return array<string, array{bool, bool}> */
    public static function realProfileScenarios(): array
    {
        return [
            'existing server' => [true, false],
            'fresh server' => [false, false],
            'source failure preserves unrelated session' => [true, true],
            'source failure on fresh server' => [false, true],
        ];
    }

    /** @param list<string> $arguments */
    private function runIsolatedTmux(string $socket, array $arguments): SymfonyProcess
    {
        $process = new SymfonyProcess(['tmux', '-L', $socket, ...$arguments], env: ['TMUX' => false], timeout: 5);
        $process->run();

        return $process;
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

    /** @param list<string> $expectedRoles */
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

    /** @return array<string, array{int, list<string>}> */
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
                    'recovery_local',
                    'recovery_download',
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
                    'recovery_local',
                    'recovery_download',
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
                    'recovery_local',
                    'recovery_download',
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

    #[DataProvider('monitoringArgumentProvider')]
    public function test_monitoring_panes_filter_the_legacy_sentinel_and_escape_real_arguments(
        ?string $vnstatArgs,
        ?string $tcptrackArgs,
        string $expectedVnstat,
        string $expectedTcptrack,
    ): void {
        $this->seedMonitoringSettings([
            'vnstat' => '1',
            'vnstat_args' => $vnstatArgs,
            'tcptrack' => '1',
            'tcptrack_args' => $tcptrackArgs,
        ]);

        $respawned = $this->buildLayoutCapturingRespawns();

        $this->assertContains($expectedVnstat, $respawned);
        $this->assertContains($expectedTcptrack, $respawned);
    }

    /**
     * @return array<string, array{?string, ?string, string, string}>
     */
    public static function monitoringArgumentProvider(): array
    {
        return [
            'the seeded legacy sentinel runs the tools bare' => [
                'NULL', 'NULL', "watch -n10 'vnstat'", 'tcptrack',
            ],
            'a blank value runs the tools bare' => [
                '', '', "watch -n10 'vnstat'", 'tcptrack',
            ],
            'a missing row runs the tools bare' => [
                null, null, "watch -n10 'vnstat'", 'tcptrack',
            ],
            'real arguments are passed through' => [
                '-i eth0',
                '-i eth0 port 443',
                "watch -n10 'vnstat '\''-i'\'' '\''eth0'\'''",
                "tcptrack '-i' 'eth0' 'port' '443'",
            ],
        ];
    }

    #[DataProvider('redisFallbackArgumentProvider')]
    public function test_redis_monitoring_uses_the_php_fallback_without_real_arguments(string $redisArgs): void
    {
        $this->seedMonitoringSettings([
            'redis' => '1',
            'redis_args' => $redisArgs,
        ]);

        $respawned = $this->buildLayoutCapturingRespawns();

        $this->assertStringContainsString(
            'php '.escapeshellarg(base_path('artisan')).' redis:monitor',
            $this->onlyCommandContaining($respawned, 'redis:monitor'),
        );
        $this->assertSame([], array_values(array_filter(
            $respawned,
            static fn (string $command): bool => str_contains($command, 'redis-cli'),
        )));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function redisFallbackArgumentProvider(): array
    {
        return [
            'legacy sentinel' => ['NULL'],
            'blank' => [''],
            'whitespace only' => ['   '],
        ];
    }

    public function test_ordinary_redis_arguments_run_through_watch_as_separate_tokens(): void
    {
        $shims = $this->makeTempDirectory('nntmux-redis-monitoring-shims');
        config([
            'database.redis.default.host' => 'redis.internal',
            'database.redis.default.port' => 6380,
        ]);
        $this->seedMonitoringSettings([
            'redis' => '1',
            'redis_args' => 'info clients',
        ]);

        $respawned = $this->buildLayoutCapturingRespawns();

        $this->writeShim($shims, 'watch', 'for argument in "$@"; do command="$argument"; done; exec sh -c "$command"');
        $this->writeShim($shims, 'redis-cli', 'for argument in "$@"; do printf \'%s\\n\' "$argument"; done');

        $this->assertSame(
            ['-h', 'redis.internal', '-p', '6380', 'info', 'clients'],
            $this->runThroughShims($this->onlyCommandContaining($respawned, 'redis-cli'), $shims),
        );
    }

    public function test_hostile_monitoring_arguments_cannot_escape_the_pane_command(): void
    {
        $shims = $this->makeTempDirectory('nntmux-monitoring-shims');
        $sentinel = $shims.'/pwned';

        $this->seedMonitoringSettings([
            'vnstat' => '1',
            'vnstat_args' => "'; touch {$sentinel} #",
            'tcptrack' => '1',
            'tcptrack_args' => "\$(touch {$sentinel})",
            'redis' => '1',
            'redis_args' => "'; touch {$sentinel}; \$(touch {$sentinel}); `touch {$sentinel}`; true && touch {$sentinel} #",
        ]);

        $respawned = $this->buildLayoutCapturingRespawns();

        // `watch` runs its argument through a shell, so the shim stands in for
        // it and hands the inner command to one as well.
        $this->writeShim($shims, 'watch', 'for argument in "$@"; do command="$argument"; done; exec sh -c "$command"');
        $this->writeShim($shims, 'vnstat', 'for argument in "$@"; do printf \'%s\\n\' "$argument"; done');
        $this->writeShim($shims, 'tcptrack', 'for argument in "$@"; do printf \'%s\\n\' "$argument"; done');
        $this->writeShim($shims, 'redis-cli', 'for argument in "$@"; do printf \'%s\\n\' "$argument"; done');

        $this->assertSame(
            ["';", 'touch', $sentinel, '#'],
            $this->runThroughShims($this->onlyCommandContaining($respawned, 'vnstat'), $shims),
        );
        $this->assertSame(
            ['$(touch', $sentinel.')'],
            $this->runThroughShims($this->onlyCommandContaining($respawned, 'tcptrack'), $shims),
        );
        $this->assertSame(
            [
                '-h',
                '127.0.0.1',
                '-p',
                '6379',
                "';",
                'touch',
                $sentinel.';',
                '$(touch',
                $sentinel.');',
                '`touch',
                $sentinel.'`;',
                'true',
                '&&',
                'touch',
                $sentinel,
                '#',
            ],
            $this->runThroughShims($this->onlyCommandContaining($respawned, 'redis-cli'), $shims),
        );
        $this->assertFileDoesNotExist($sentinel, 'the hostile arguments never reached a shell as code');
    }

    private function writeShim(string $directory, string $name, string $body): void
    {
        $path = $directory.'/'.$name;
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);
    }

    /**
     * Run a generated pane command for real, with the monitoring tools shimmed
     * onto PATH, and return the arguments the tool was handed.
     *
     * @return list<string>
     */
    private function runThroughShims(string $command, string $shims): array
    {
        $process = SymfonyProcess::fromShellCommandline($command, null, ['PATH' => $shims.':'.getenv('PATH')]);
        $process->setTimeout(10);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Build a full layout against a fake tmux and return every command a pane
     * was respawned with.
     *
     * @return list<string>
     */
    private function buildLayoutCapturingRespawns(): array
    {
        $nextPaneId = 1;
        $respawned = [];

        Process::fake(function (PendingProcess $process) use (&$nextPaneId, &$respawned) {
            $command = $process->command;
            if (! is_array($command)) {
                return Process::result();
            }

            if (in_array('which', $command, true)) {
                return Process::result('/usr/bin/'.end($command));
            }

            if (in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }

            if (in_array('new-session', $command, true)
                || in_array('new-window', $command, true)
                || in_array('split-window', $command, true)) {
                return Process::result('%'.$nextPaneId++."\n");
            }

            if (in_array('respawn-pane', $command, true)) {
                $respawned[] = (string) end($command);
            }

            return Process::result();
        });

        $layoutBuilder = new TmuxLayoutBuilder(new TmuxSessionManager('test-session'));

        $this->assertTrue($layoutBuilder->buildLayout(0), (string) $layoutBuilder->lastError());

        return $respawned;
    }

    /**
     * @param  array<string, string|null>  $settings
     */
    private function seedMonitoringSettings(array $settings): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        foreach ($settings as $name => $value) {
            if ($value === null) {
                continue;
            }

            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    /**
     * @param  list<string>  $commands
     */
    private function onlyCommandContaining(array $commands, string $needle): string
    {
        $matches = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, $needle),
        ));

        $this->assertCount(1, $matches, "exactly one pane command mentions {$needle}");

        return $matches[0];
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

    #[DataProvider('progressiveBackfillSleepProvider')]
    public function test_backfill_sleep_stays_an_integer_number_of_seconds(
        int $progressive,
        int $collections,
        int $expectedSleep,
    ): void {
        Process::fake(function (PendingProcess $process) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tbackfill\n");
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');

        $this->assertTrue($runner->runBackfill([
            'settings' => ['backfill' => 1, 'back_timer' => 30, 'progressive' => $progressive],
            'killswitch' => ['coll' => false, 'pp' => false],
            'counts' => ['now' => ['collections_table' => $collections]],
        ]));

        $this->assertMatchesRegularExpression(
            "/(?:showsleep\\.php'|sleep) ".$expectedSleep.'(?:\D|$)/',
            $this->respawnedCommand(),
        );
    }

    /**
     * The divisor is 500 collections per second of sleep, so a backlog of
     * 1,000,000 is what reaches the brief's worked sleep of 2000 seconds; its
     * stated backlog of 100,000 yields 200. The formula is the fixed point.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function progressiveBackfillSleepProvider(): array
    {
        return [
            'progressive off keeps the base timer' => [0, 100000, 30],
            'progressive below the threshold keeps the base timer' => [1, 1000, 30],
            'progressive engages with a large backlog' => [1, 1000000, 2000],
            'progressive rounds down to whole seconds' => [1, 100499, 200],
        ];
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

    /**
     * The collections valve parks only Backfill; the post-processing valve
     * parks the header pane too, so the back of the pipeline can catch up.
     *
     * @param  array{coll: bool, pp: bool}  $killswitch
     */
    #[DataProvider('safetyValveProvider')]
    public function test_safety_valves_park_the_panes_their_settings_name(
        array $killswitch,
        bool $binariesParked,
        string $binariesCommand,
    ): void {
        $respawned = [];
        Process::fake(function (PendingProcess $process) use (&$respawned) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%8\tbinaries\n%9\tbackfill\n");
            }
            if (is_array($process->command) && in_array('respawn-pane', $process->command, true)) {
                $respawned[$process->command[array_search('-t', $process->command, true) + 1]] = $process->command;
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');
        $runner->runBinariesUpdate([
            'settings' => ['binaries_run' => 1, 'bins_timer' => 10],
            'killswitch' => $killswitch,
        ]);
        $runner->runBackfill([
            'settings' => ['backfill' => 1, 'back_timer' => 30, 'progressive' => 0],
            'killswitch' => $killswitch,
            'counts' => ['now' => ['collections_table' => 0]],
        ]);

        $this->assertArrayHasKey('%8', $respawned, 'the binaries pane is respawned');
        $this->assertArrayHasKey('%9', $respawned, 'the backfill pane is respawned');
        $this->assertContains('-k', $respawned['%9']);
        $this->assertStringContainsString('Backfill has been disabled: kill limit exceeded', (string) end($respawned['%9']));
        $this->assertSame($binariesParked, in_array('-k', $respawned['%8'], true));
        $this->assertStringContainsString($binariesCommand, (string) end($respawned['%8']));
    }

    /**
     * @return array<string, array{array{coll: bool, pp: bool}, bool, string}>
     */
    public static function safetyValveProvider(): array
    {
        return [
            'collections valve parks only backfill' => [
                ['coll' => true, 'pp' => false],
                false,
                'multiprocessing:safe binaries',
            ],
            'post-processing valve parks binaries and backfill' => [
                ['coll' => false, 'pp' => true],
                true,
                'Update Binaries has been disabled: postprocess kill limit exceeded',
            ],
        ];
    }

    /**
     * @param  array<string, int>  $settings
     * @param  array<string, int>  $counts
     */
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

        // A "no work" park never kills a running worker: tmux refuses while the
        // batch runs, and the next pass parks the finished pane.
        Process::assertRan(function (PendingProcess $process) use ($displayName, $reason): bool {
            return is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && ! in_array('-k', $process->command, true)
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
            'additional only' => [
                'ppadditional',
                'post_additional',
                ['post' => 1],
                ['processnfo' => 0, 'work_available' => 0],
                'Post-process Additional',
                'no additional work to process',
                'multiprocessing:postprocess add',
            ],
            'additional and NFO' => [
                'ppadditional',
                'post_additional',
                ['post' => 3],
                ['processnfo' => 0, 'work_available' => 0],
                'Post-process Additional',
                'no additional work or NFOs to process',
                'multiprocessing:postprocess',
            ],
            'fix names' => [
                'fixnames',
                'fix_names',
                ['fix_names' => 1],
                ['name_work_available' => 0, 'processpredbft' => 0],
                'Fix Release Names',
                'no releases to process',
                'releases:fix-names',
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

    /** @return array<string, array{bool}> */
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
            $expectedOrder[] = "'/usr/bin/timeout' -k 10 900 php ";
            $expectedOrder[] = "releases:fix-names {$level} --update --category=other --set-status --show";
            $expectedOrder[] = "fix-names {$level} timed out after 900s";
        }
        $expectedOrder[] = "'/usr/bin/timeout' -k 10 900 php ";
        $expectedOrder[] = 'multiprocessing:fixrelnames standard';
        $expectedOrder[] = 'fix-names standard sweep timed out after 900s';
        $expectedOrder[] = "'/usr/bin/timeout' -k 10 900 php ";
        $expectedOrder[] = 'multiprocessing:fixrelnames predbft';
        $expectedOrder[] = 'fix-names predb full-text sweep timed out after 900s';
        $expectedOrder[] = "date +'%Y-%m-%d %T'";

        $this->assertAppearInOrder($expectedOrder, $command);
        $this->assertSame(11, substr_count($command, '| tee -a '), 'every step logs to the pane log');
        $this->assertSame(11, substr_count($command, "'/usr/bin/timeout' -k 10 900 "), 'every step is bounded');
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

    #[DataProvider('nameAvailabilityProvider')]
    public function test_fix_names_uses_fresh_availability_instead_of_cached_display_count(int $available, int $cachedCount): void
    {
        $this->fakeFixNamesPane();
        $this->runnerWithTimeoutBinary('timeout')->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300],
            'counts' => ['now' => ['name_work_available' => $available, 'processrenames' => $cachedCount]],
        ]);
        $command = $this->respawnedCommand();
        if ($available === 1) {
            $this->assertStringContainsString('multiprocessing:fixrelnames standard', $command);
        } else {
            $this->assertStringContainsString('no releases to process', $command);
            $this->assertStringNotContainsString('multiprocessing:fixrelnames standard', $command);
        }
    }

    /** @return array<string, array{int, int}> */
    public static function nameAvailabilityProvider(): array
    {
        return ['new work after empty snapshot' => [1, 0], 'settled work after pending snapshot' => [0, 100]];
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

        $this->assertStringContainsString("'timeout' -k 10 {$expected} php ", $this->respawnedCommand());
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
        $this->assertStringNotContainsString("timeout' -k", $command);
        $this->assertStringNotContainsString('timed out', $command);
        $this->assertStringNotContainsString('bounded=1', $command);
        $this->assertStringStartsWith("c=\$(date +%s); echo 'fix_names_timeout is not enforced", $command);
        $this->assertMatchesRegularExpression('/multiprocessing:fixrelnames standard; rc=\$\?; .*\} 2>&1 \| tee -a /', $command);
    }

    #[DataProvider('paneLogSwitchProvider')]
    public function test_pane_output_reaches_a_log_file_only_when_tmux_write_logs_is_on(bool $enabled): void
    {
        $this->freezeTime();
        $logs = $this->makeTempDirectory('nntmux-pane-logs');
        config(['tmux.paths.logs' => $logs, 'tmux.write_logs' => $enabled]);
        $this->fakeFixNamesPane();

        $this->runnerWithTimeoutBinary(null)->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300],
            'counts' => ['now' => ['processrenames' => 1]],
        ]);

        $expected = $enabled ? $logs.'/fixnames-'.now()->format('Y_m_d').'.log' : '/dev/null';
        $command = $this->respawnedCommand();
        $this->assertStringContainsString('| tee -a '.escapeshellarg($expected), $command);
        if (! $enabled) {
            $this->assertStringNotContainsString($logs, $command);
        }
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function paneLogSwitchProvider(): array
    {
        return [
            'off writes to /dev/null' => [false],
            'on writes under the tmux log directory' => [true],
        ];
    }

    #[DataProvider('spacedLogFolderProvider')]
    public function test_pane_output_reaches_a_log_folder_whose_name_has_a_space(string $pane): void
    {
        $this->freezeTime();
        $parent = $this->makeTempDirectory('nntmux-spaced-logs');
        $logs = $parent.'/my logs';
        config(['tmux.paths.logs' => $logs, 'tmux.write_logs' => true]);

        if ($pane === 'fixnames') {
            $this->fakeFixNamesPane();
            $this->runnerWithTimeoutBinary(null)->runPaneTask('fixnames', [], [
                'settings' => ['fix_names' => 1, 'fix_timer' => 1],
                'counts' => ['now' => ['processrenames' => 1]],
            ]);
            $command = $this->withFakeWorkers($this->respawnedCommand());
            $expected = ['fix_names_timeout is not enforced', 'fake worker output'];
        } else {
            $command = (new TmuxTaskRunner('test-session'))->buildCommand('echo pane output', ['log_pane' => 'scraper']);
            $expected = ['pane output'];
        }

        $process = new SymfonyProcess(['/bin/sh', '-c', $command]);
        $process->setTimeout(60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $log = $logs.'/'.$pane.'-'.now()->format('Y_m_d').'.log';
        $this->assertFileExists($log);
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, (string) file_get_contents($log));
        }
        $this->assertSame(['my logs'], array_values(array_diff(scandir($parent) ?: [], ['.', '..'])), 'no stray file beside the log folder');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spacedLogFolderProvider(): array
    {
        return [
            'fix-names pane without a timeout binary' => ['fixnames'],
            'buildCommand log target' => ['scraper'],
        ];
    }

    /**
     * @param  array<string, mixed>  $runVar
     */
    #[DataProvider('quotedPathPaneProvider')]
    public function test_pane_commands_quote_the_paths_they_run(string $task, string $role, array $runVar, string $path, ?int $sleep): void
    {
        $this->fakePaneRole($role);

        $runner = new TmuxTaskRunner('test-session');
        $this->assertTrue(match ($task) {
            'binaries' => $runner->runBinariesUpdate($runVar),
            'backfill' => $runner->runBackfill($runVar),
            'releases' => $runner->runReleasesUpdate($runVar),
            default => $runner->runPaneTask($task, [], $runVar),
        });

        $command = $this->respawnedCommand();
        $expected = $path === 'artisan' ? 'php '.escapeshellarg(base_path('artisan')).' ' : escapeshellarg(PHP_BINARY).' artisan ';
        $this->assertStringContainsString($expected, $command);
        if ($sleep !== null) {
            $this->assertStringEndsWith(' php '.escapeshellarg(base_path('app/Services/Tmux/Scripts/showsleep.php')).' '.$sleep, $command);
        }
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, string, ?int}>
     */
    public static function quotedPathPaneProvider(): array
    {
        $killswitch = ['coll' => false, 'pp' => false];

        return [
            'IRC scraper' => ['scraper', 'irc_scraper', ['constants' => ['run_ircscraper' => 1]], 'artisan', null],
            'binaries' => ['binaries', 'binaries', ['settings' => ['binaries_run' => 1, 'bins_timer' => 7], 'killswitch' => $killswitch], 'php', 7],
            'backfill' => ['backfill', 'backfill', ['settings' => ['backfill' => 1, 'back_timer' => 8, 'progressive' => 0], 'killswitch' => $killswitch, 'counts' => ['now' => ['collections_table' => 0]]], 'php', 8],
            'releases' => ['releases', 'releases', ['settings' => ['releases_run' => 1, 'rel_timer' => 9], 'constants' => ['sequential' => 0]], 'php', 9],
            'fix names' => ['fixnames', 'fix_names', ['settings' => ['fix_names' => 1, 'fix_timer' => 1], 'counts' => ['now' => ['processrenames' => 1]]], 'artisan', 1],
            'remove crap all' => ['removecrap', 'remove_crap', ['settings' => ['fix_crap_opt' => 'All', 'crap_timer' => 2]], 'artisan', 2],
            'remove crap custom' => ['removecrap', 'remove_crap', ['settings' => ['fix_crap_opt' => 'Custom', 'fix_crap' => 'blacklist', 'crap_timer' => 3]], 'artisan', 3],
            'additional' => ['ppadditional', 'post_additional', ['settings' => ['post' => 1, 'post_timer' => 4], 'counts' => ['now' => ['work_available' => 1]]], 'php', 4],
            'TV and anime' => ['tv', 'post_tv', ['settings' => ['post_non' => 1, 'processtvrage' => 1, 'processanime' => 1, 'post_timer_non' => 5], 'counts' => ['now' => ['processtv' => 1, 'processanime' => 1]]], 'php', 5],
            'movies' => ['movies', 'post_movies', ['settings' => ['post_non' => 1, 'processmovies' => 1, 'post_timer_non' => 6], 'counts' => ['now' => ['processmovies' => 1]]], 'php', 6],
            'metadata and audio' => ['amazon', 'post_metadata', ['settings' => ['post_amazon' => 1, 'post_timer_amazon' => 7], 'counts' => ['now' => ['processmusic' => 1, 'audio_work_available' => 1]]], 'php', 7],
        ];
    }

    public function test_a_bounded_step_quotes_the_timeout_binary_path(): void
    {
        $this->fakeFixNamesPane();

        $this->runnerWithTimeoutBinary('/opt/core utils/timeout')->runPaneTask('fixnames', [], [
            'settings' => ['fix_names' => 1, 'fix_timer' => 300, 'fix_names_timeout' => 900],
            'counts' => ['now' => ['processrenames' => 1]],
        ]);

        $this->assertStringContainsString(
            escapeshellarg('/opt/core utils/timeout').' -k 10 900 php '.escapeshellarg(base_path('artisan')).' releases:fix-names',
            $this->respawnedCommand(),
        );
    }

    public function test_engine_start_runs_the_monitor_script_by_its_quoted_path(): void
    {
        $this->seedMonitoringSettings(['sequential' => '0', 'running' => '0']);
        $nextPaneId = 1;
        Process::fake(function (PendingProcess $process) use (&$nextPaneId) {
            $command = $process->command;
            if (is_string($command)) {
                return Process::result(str_contains($command, 'which tmux') ? '/usr/bin/tmux' : '');
            }
            if (in_array('has-session', $command, true)) {
                return Process::result('', '', 1);
            }
            if (array_intersect(['new-session', 'new-window', 'split-window'], $command) !== []) {
                return Process::result('%'.$nextPaneId++."\n");
            }
            if (in_array('list-panes', $command, true)) {
                return Process::result("%1\tmonitor\n");
            }
            if (in_array('respawn-pane', $command, true)) {
                $this->respawned[] = (string) end($command);
            }

            return Process::result();
        });

        $this->artisan('tmux:start', ['--session' => 'test-monitor'])->assertSuccessful();

        $this->assertSame(
            'php '.escapeshellarg(base_path('app/Services/Tmux/Scripts/monitor.php')),
            $this->onlyCommandContaining($this->respawned, 'monitor.php'),
        );
    }

    /**
     * Each helper script, copied under a folder whose name has a space, runs
     * the artisan file beside it.
     *
     * @param  list<string>  $arguments
     */
    #[DataProvider('helperScriptProvider')]
    public function test_helper_scripts_run_artisan_from_a_folder_whose_name_has_a_space(string $script, array $arguments, string $expected): void
    {
        $root = $this->makeTempDirectory('nntmux-scripts').'/nntmux root';
        $scripts = $root.'/app/Services/Tmux/Scripts';
        mkdir($scripts, 0755, true);
        copy(base_path('app/Services/Tmux/Scripts/'.$script), $scripts.'/'.$script);
        file_put_contents($root.'/artisan', '<?php echo implode(" ", array_slice($argv, 1));');

        $process = new SymfonyProcess([PHP_BINARY, $scripts.'/'.$script, ...$arguments], env: ['PATH' => dirname(PHP_BINARY).':'.getenv('PATH')]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($expected, $process->getOutput());
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function helperScriptProvider(): array
    {
        return [
            'monitor' => ['monitor.php', ['--session=x'], 'tmux:monitor --session=x'],
            'update groups' => ['update_groups.php', [], 'groups:update'],
            'standard fix names' => ['groupfixrelnames.php', ['standard a 10 1 1'], 'releases:fix-names-group standard --guid-char=a --limit=10'],
            'predb full-text fix names' => ['groupfixrelnames.php', ['predbft 0 10 2 4'], 'releases:fix-names-group predbft --limit=10 --thread=2 --workers=4'],
        ];
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
        mkdir(storage_path('tmux/panes'), 0755, true);
        $runner = new TmuxTaskRunner('test-session');
        $chain = 'c=100; '.$runner->boundedStep(TmuxPaneRole::FixNames, 'fix-names 7', 'sleep 30', 1, $log).'; echo next-step';

        $started = microtime(true);
        $process = SymfonyProcess::fromShellCommandline($chain);
        $process->setTimeout(20);
        $process->run();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(15, $elapsed, 'the hung step must be cut off at the limit');
        $this->assertMatchesRegularExpression('/fix-names 7 timed out after 1s, moving on\n.*next-step/s', $process->getOutput());
        $this->assertSame(1, substr_count((string) file_get_contents($log), 'timed out after 1s'));
        $this->assertStringNotContainsString('next-step', (string) file_get_contents($log), 'only the step itself is logged');
        $this->assertMatchesRegularExpression(
            '/^cycle=100 step=fix-names 7 exit=124 start=\d+ end=\d+ bounded=1$/',
            trim((string) file_get_contents(storage_path('tmux/panes/fix_names.status'))),
        );
    }

    #[DataProvider('nicenessSettingProvider')]
    public function test_every_pane_command_takes_its_priority_from_the_shared_helper(?string $stored, int $expected): void
    {
        $this->seedNicenessSetting($stored);

        $respawned = [];
        Process::fake(function (PendingProcess $process) use (&$respawned) {
            if (! is_array($process->command)) {
                return Process::result();
            }

            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\tpost_additional\n%2\tpost_metadata\n%3\tpost_tv\n");
            }

            if (in_array('respawn-pane', $process->command, true)) {
                $respawned[] = (string) end($process->command);
            }

            return Process::result();
        });

        $runner = new TmuxTaskRunner('test-session');
        $runVar = [
            'settings' => [
                'post' => 1,
                'post_amazon' => 1,
                'post_non' => 1,
                'processtvrage' => 1,
            ],
            'counts' => ['now' => [
                'work_available' => 5,
                'processmusic' => 5,
                'processtv' => 5,
            ]],
        ];

        // The first two panes are the ones that used to read the setting directly.
        foreach (['ppadditional', 'amazon', 'tv'] as $task) {
            $this->assertTrue($runner->runPaneTask($task, [], $runVar), "{$task} pane ran");
        }

        $this->assertCount(3, $respawned);
        foreach ($respawned as $command) {
            $this->assertStringNotContainsString('nice -n ', $command, 'nice never runs without a priority');
            $this->assertMatchesRegularExpression('/nice -n-?\d/', $command);

            preg_match_all('/nice -n(-?\d+)/', $command, $matches);
            $this->assertSame(
                array_fill(0, count($matches[1]), (string) $expected),
                $matches[1],
                "every nice invocation in [{$command}] uses the resolved priority",
            );
        }
    }

    /**
     * @return array<string, array{?string, int}>
     */
    public static function nicenessSettingProvider(): array
    {
        return [
            'missing row falls back to the default' => [null, TmuxTaskRunner::DEFAULT_NICENESS],
            'blanked admin field falls back to the default' => ['', TmuxTaskRunner::DEFAULT_NICENESS],
            'stored value is used' => ['5', 5],
            'a stored zero is a real value' => ['0', 0],
            'a stored negative value is used' => ['-5', -5],
        ];
    }

    /**
     * Every builder shape, run for real through /bin/sh with each worker
     * replaced by a fake that prints and exits 3.
     *
     * @param  array<string, mixed>  $runVar
     */
    #[DataProvider('supervisedPaneShapeProvider')]
    public function test_each_pane_command_records_every_step_exit_and_ends_the_cycle_with_its_sleep(
        string $task,
        string $role,
        array $runVar,
        ?string $timeoutBinary,
        int $steps,
        int $sleep,
    ): void {
        if ($timeoutBinary === 'timeout') {
            $timeoutBinary = (new ExecutableFinder)->find('timeout');
            if ($timeoutBinary === null) {
                $this->markTestSkipped('coreutils timeout is not installed on this host.');
            }
        }
        config(['nntmux_srrdb.enabled' => false]);
        $logs = $this->makeTempDirectory('nntmux-pane-logs');
        config(['tmux.paths.logs' => $logs]);
        $this->seedNicenessSetting('5');
        config(['tmux.write_logs' => true]);
        $this->fakePaneRole($role);

        $runner = $this->runnerWithTimeoutBinary($timeoutBinary);
        $this->assertTrue(match ($task) {
            'binaries' => $runner->runBinariesUpdate($runVar),
            'backfill' => $runner->runBackfill($runVar),
            'releases' => $runner->runReleasesUpdate($runVar),
            default => $runner->runPaneTask($task, [], $runVar),
        });

        $process = new SymfonyProcess(['/bin/sh', '-c', $this->withFakeWorkers($this->respawnedCommand())]);
        $process->setTimeout(60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $lines = file(storage_path("tmux/panes/{$role}.status"), FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount($steps + 1, $lines, implode("\n", $lines));
        foreach (array_slice($lines, 0, $steps) as $line) {
            $this->assertMatchesRegularExpression('/^cycle=\d+ step=\S.* exit=3 start=\d+ end=\d+( bounded=1)?$/', $line);
        }
        $this->assertMatchesRegularExpression("/^cycle=\\d+ cycle_end=1 sleep={$sleep} end=\\d+$/", (string) end($lines));
        $this->assertCount(1, array_unique(array_map(static fn (string $line): string => (string) strtok($line, ' '), $lines)), 'one cycle id');

        $logged = implode('', array_map('file_get_contents', glob($logs.'/*.log') ?: []));
        $this->assertSame($steps, substr_count($logged, 'fake worker output'), 'the log holds every step\'s output');
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, ?string, int, int}>
     */
    public static function supervisedPaneShapeProvider(): array
    {
        $killswitch = ['coll' => false, 'pp' => false];

        return [
            'binaries' => ['binaries', 'binaries', ['settings' => ['binaries_run' => 1, 'bins_timer' => 7], 'killswitch' => $killswitch], null, 1, 7],
            'backfill' => ['backfill', 'backfill', ['settings' => ['backfill' => 1, 'back_timer' => 8, 'progressive' => 0], 'killswitch' => $killswitch, 'counts' => ['now' => ['collections_table' => 0]]], null, 1, 8],
            'releases' => ['releases', 'releases', ['settings' => ['releases_run' => 1, 'rel_timer' => 9], 'constants' => ['sequential' => 0]], null, 1, 9],
            'fix names under timeout' => ['fixnames', 'fix_names', ['settings' => ['fix_names' => 1, 'fix_timer' => 0], 'counts' => ['now' => ['processrenames' => 1]]], 'timeout', 11, 0],
            'fix names without timeout' => ['fixnames', 'fix_names', ['settings' => ['fix_names' => 1, 'fix_timer' => 1], 'counts' => ['now' => ['processrenames' => 1]]], null, 11, 1],
            'remove crap all' => ['removecrap', 'remove_crap', ['settings' => ['fix_crap_opt' => 'All', 'crap_timer' => 2]], null, 1, 2],
            'remove crap custom' => ['removecrap', 'remove_crap', ['settings' => ['fix_crap_opt' => 'Custom', 'fix_crap' => 'blacklist,executable', 'crap_timer' => 3]], null, 2, 3],
            'additional and NFO' => ['ppadditional', 'post_additional', ['settings' => ['post' => 3, 'post_timer' => 4], 'counts' => ['now' => ['work_available' => 1, 'processnfo' => 1]]], null, 2, 4],
            'TV and anime' => ['tv', 'post_tv', ['settings' => ['post_non' => 1, 'processtvrage' => 1, 'processanime' => 1, 'post_timer_non' => 5], 'counts' => ['now' => ['processtv' => 1, 'processanime' => 1]]], null, 2, 5],
            'movies' => ['movies', 'post_movies', ['settings' => ['post_non' => 1, 'processmovies' => 1, 'post_timer_non' => 6], 'counts' => ['now' => ['processmovies' => 1]]], null, 1, 6],
            'metadata and audio' => ['amazon', 'post_metadata', ['settings' => ['post_amazon' => 1, 'post_timer_amazon' => 7], 'counts' => ['now' => ['processmusic' => 1, 'audio_work_available' => 1]]], null, 2, 7],
            'recovery discovery and publication' => ['recovery_local', 'recovery_local', [], null, 2, 5],
            'recovery downloads' => ['recovery_download', 'recovery_download', [], null, 1, 5],
        ];
    }

    public function test_a_step_reports_its_workers_failed_children_on_its_status_line(): void
    {
        mkdir(storage_path('tmux/panes'), 0755, true);
        $runner = new class('test-session') extends TmuxTaskRunner
        {
            public function step(string $command, string $log): string
            {
                return $this->supervisedStep(TmuxPaneRole::PostAdditional, 'add', $command, $log);
            }
        };
        $worker = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('file_put_contents(getenv("NNTMUX_PANE_CHILDREN"), "1/3");');

        $process = new SymfonyProcess(['/bin/sh', '-c', 'c=200; '.$runner->step($worker, $this->makeTempPath('nntmux-add', '.log'))]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertMatchesRegularExpression(
            '/^cycle=200 step=add exit=0 start=\d+ end=\d+ children_failed=1\/3$/',
            trim((string) file_get_contents(storage_path('tmux/panes/post_additional.status'))),
            'failed children are reported, and the worker\'s own exit stays 0',
        );
        $this->assertFileDoesNotExist(storage_path('tmux/panes/post_additional.children'));
    }

    public function test_a_failed_cycle_is_counted_once_and_shown_on_the_monitor(): void
    {
        Log::spy();
        $this->fakePaneRole('binaries');
        $this->writeStatus('binaries', [
            'cycle=900 step=binaries exit=0 start=900 end=905',
            'cycle=1000 step=binaries exit=2 start=1000 end=1030',
            'not a status line',
            'cycle=1000 cycle_end=1 sleep=10 end=1031',
        ]);

        $this->travelTo(Carbon::createFromTimestamp(1035));
        (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar());
        $this->assertSame('', file_get_contents(storage_path('tmux/panes/binaries.status')), 'the counted cycle is emptied');

        // The same cycle read again changes nothing and logs nothing.
        $this->writeStatus('binaries', ['cycle=1000 step=binaries exit=2 start=1000 end=1030', 'cycle=1000 cycle_end=1 sleep=10 end=1031']);
        (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'binaries failed: step binaries exited 2 after 31s')
                && $context['exit'] === 2 && $context['duration'] === 31)
            ->once();

        $supervision = (new TmuxPaneSupervisor)->summaries();
        $this->assertSame(array_keys(TmuxPaneSupervisor::ROLES), array_keys($supervision));
        $this->assertSame('Binaries', $supervision['binaries']['label']);
        $this->assertSame(1, $supervision['binaries']['failures']);
        $this->assertSame(['step' => 'binaries', 'exit' => 2, 'duration' => 31, 'children_failed' => null], $supervision['binaries']['last']);

        $display = (string) preg_replace('/\e\[[0-9;]*m/', '', (new TmuxOutput)->renderSupervision($supervision));
        $this->assertMatchesRegularExpression('/Binaries\s+exit 2 in 31s\s+1\n/', $display);
        $this->assertMatchesRegularExpression('/Fix Names\s+no runs yet\s*\n/', $display);
        $this->assertSame(count(TmuxPaneSupervisor::ROLES) + 3, substr_count($display, "\n"), 'blank line, header, separator, one line per role');
    }

    public function test_a_cycle_without_its_end_line_is_never_counted(): void
    {
        Log::spy();
        $this->fakePaneRole('binaries');
        $this->writeStatus('binaries', [
            // Killed mid-step: no cycle_end.
            'cycle=1000 step=binaries exit=2 start=1000 end=1030',
            'cycle=1100 step=binaries exit=0 start=1100 end=1110',
            'cycle=1100 cycle_end=1 sleep=10 end=1110',
        ]);

        (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar());

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(['step' => 'binaries', 'exit' => 0, 'duration' => 10, 'children_failed' => null], (new TmuxPaneSupervisor)->summaries()['binaries']['last']);

        // A running cycle has no end line yet and stays untouched.
        $this->writeStatus('binaries', ['cycle=1200 step=binaries exit=4 start=1200 end=1201']);
        (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar());

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, (new TmuxPaneSupervisor)->summaries()['binaries']['failures']);
        $this->assertStringContainsString('cycle=1200', (string) file_get_contents(storage_path('tmux/panes/binaries.status')));
    }

    public function test_only_a_bounded_step_cut_off_by_timeout_counts_as_moving_on(): void
    {
        $this->fakePaneRole('fix_names');
        $this->writeStatus('fix_names', [
            'cycle=1000 step=fix-names 3 exit=124 start=1000 end=1010 bounded=1',
            'cycle=1000 step=fix-names 5 exit=137 start=1010 end=1020 bounded=1 children_failed=1/4',
            'cycle=1000 step=fix-names standard sweep exit=0 start=1020 end=1030 children_failed=2/5',
            'cycle=1000 cycle_end=1 sleep=10 end=1030',
        ]);
        $runVar = ['settings' => ['fix_names' => 1, 'fix_timer' => 10], 'counts' => ['now' => ['processrenames' => 1]]];
        $runner = $this->runnerWithTimeoutBinary('timeout');

        $runner->runPaneTask('fixnames', [], $runVar);
        $summary = (new TmuxPaneSupervisor)->summaries()['fix_names'];
        $this->assertSame(0, $summary['failures']);
        $this->assertSame(['step' => 'fix-names standard sweep', 'exit' => 0, 'duration' => 30, 'children_failed' => '3/9'], $summary['last']);

        $this->writeStatus('fix_names', [
            'cycle=1100 step=fix-names 3 exit=137 start=1100 end=1110',
            'cycle=1100 cycle_end=1 sleep=10 end=1110',
        ]);
        $runner->runPaneTask('fixnames', [], $runVar);
        $summary = (new TmuxPaneSupervisor)->summaries()['fix_names'];
        $this->assertSame(1, $summary['failures']);
        $this->assertSame('fix-names 3', $summary['last']['step']);
        $this->assertSame(137, $summary['last']['exit']);
    }

    /**
     * The monitor passes every few seconds; a role failing three cycles in a
     * row is held off until its cycle's end + sleep + wait, the wait doubling
     * up to the cap and resetting on success.
     */
    public function test_a_pane_failing_three_cycles_in_a_row_backs_off_doubling_up_to_the_cap(): void
    {
        config(['tmux.monitor.delay' => 10, 'tmux.monitor.failures_before_backoff' => 3, 'tmux.monitor.max_backoff_seconds' => 50]);
        Log::spy();
        $respawns = [];
        Process::fake(function (PendingProcess $process) use (&$respawns) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\tbinaries\n");
            }
            if (is_array($process->command) && in_array('respawn-pane', $process->command, true)) {
                $respawns[] = $process->command;
            }

            return Process::result();
        });
        $pass = function (int $at) use (&$respawns): array {
            $respawns = [];
            $this->travelTo(Carbon::createFromTimestamp($at));
            (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar(sleep: 0));

            return $respawns;
        };
        $launched = static fn (array $respawns): bool => $respawns !== []
            && str_contains((string) end($respawns[0]), 'multiprocessing:safe binaries');
        $failCycle = fn (int $start, int $end) => $this->writeStatus('binaries', [
            "cycle={$start} step=binaries exit=1 start={$start} end={$end}",
            "cycle={$start} cycle_end=1 sleep=0 end={$end}",
        ]);

        // Two failures: still launched.
        $failCycle(1000, 1005);
        $this->assertTrue($launched($pass(1006)));
        $failCycle(1006, 1010);
        $this->assertTrue($launched($pass(1012)));

        // Third failure: the sleep is 0, so the first wait is the 10 s monitor delay.
        $failCycle(1012, 1020);
        foreach ([1021, 1025, 1029] as $at) {
            $respawns = $pass($at);
            $this->assertFalse($launched($respawns), "no launch at {$at}");
            $this->assertCount(1, $respawns);
            $this->assertNotContains('-k', $respawns[0], 'a backoff park never kills');
            $this->assertStringContainsString(
                'Update Binaries is backing off after 3 failed runs (last: binaries exit 1); next try '
                    .Carbon::createFromTimestamp(1030, config('app.timezone'))->format('H:i:s'),
                (string) end($respawns[0]),
            );
        }
        $this->assertTrue($launched($pass(1030)));

        // Fourth: 20 s. Fifth: 40 s. Sixth: capped at 50 s.
        foreach ([[1030, 1040, 1060], [1060, 1061, 1101], [1101, 1102, 1152]] as [$start, $end, $next]) {
            $failCycle($start, $end);
            $this->assertFalse($launched($pass($end + 1)));
            $this->assertFalse($launched($pass($next - 1)));
            $this->assertTrue($launched($pass($next)), "launch at {$next}");
        }

        // One success resets the count and the wait.
        $this->writeStatus('binaries', ['cycle=1152 step=binaries exit=0 start=1152 end=1160', 'cycle=1152 cycle_end=1 sleep=0 end=1160']);
        $this->assertTrue($launched($pass(1161)));
        $this->assertSame(0, (new TmuxPaneSupervisor)->summaries()['binaries']['failures']);
        $failCycle(1161, 1170);
        $this->assertTrue($launched($pass(1171)), 'one failure after recovering is not a backoff');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'is backing off for 10s after 3 failed runs'))->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, 'binaries recovered after 6 failed runs'))->once();
    }

    public function test_the_first_wait_is_the_panes_own_sleep_when_that_is_longer_than_the_monitor_delay(): void
    {
        config(['tmux.monitor.delay' => 10]);
        $this->fakePaneRole('binaries');
        foreach ([1000, 2000, 3000] as $cycle) {
            $this->writeStatus('binaries', [
                "cycle={$cycle} step=binaries exit=1 start={$cycle} end=".($cycle + 5),
                "cycle={$cycle} cycle_end=1 sleep=60 end=".($cycle + 5),
            ]);
            $this->travelTo(Carbon::createFromTimestamp($cycle + 6));
            (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar(sleep: 60));
        }

        // end 3005 + sleep 60 + wait 60
        $this->travelTo(Carbon::createFromTimestamp(3124));
        $this->assertNotNull((new TmuxPaneSupervisor)->backoffMessage(TmuxPaneRole::Binaries, 'Update Binaries'));
        $this->travelTo(Carbon::createFromTimestamp(3125));
        $this->assertNull((new TmuxPaneSupervisor)->backoffMessage(TmuxPaneRole::Binaries, 'Update Binaries'));
    }

    public function test_disabled_in_settings_beats_backoff_and_clears_it_and_backoff_beats_no_work(): void
    {
        $this->fakePaneRole('post_movies');
        $runVar = ['settings' => ['post_non' => 1, 'processmovies' => 1, 'post_timer_non' => 0], 'counts' => ['now' => ['processmovies' => 0]]];
        foreach ([1000, 1100, 1200] as $cycle) {
            $this->writeStatus('post_movies', ["cycle={$cycle} step=mov exit=1 start={$cycle} end={$cycle}", "cycle={$cycle} cycle_end=1 sleep=0 end={$cycle}"]);
            $this->travelTo(Carbon::createFromTimestamp($cycle + 1));
            (new TmuxTaskRunner('test-session'))->runPaneTask('movies', [], $runVar);
        }
        $this->assertStringContainsString('Post-process Movies is backing off after 3 failed runs', $this->respawnedCommands()[2]);

        $runVar['settings']['post_non'] = 0;
        (new TmuxTaskRunner('test-session'))->runPaneTask('movies', [], $runVar);
        $this->assertStringContainsString('Post-process Movies has been disabled: disabled in settings', $this->respawnedCommands()[3]);
        $this->assertNull((new TmuxPaneSupervisor)->summaries()['post_movies']['last'], 'the role\'s entry is cleared');

        $runVar['settings']['post_non'] = 1;
        (new TmuxTaskRunner('test-session'))->runPaneTask('movies', [], $runVar);
        $this->assertStringContainsString('Post-process Movies has been disabled: no work available', $this->respawnedCommands()[4]);
    }

    public function test_the_monitor_starts_every_role_clean_and_reads_unreadable_state_as_empty(): void
    {
        $this->writeStatus('binaries', ['cycle=1000 step=binaries exit=1 start=1000 end=1001', 'cycle=1000 cycle_end=1 sleep=0 end=1001']);
        $this->fakePaneRole('binaries');
        (new TmuxTaskRunner('test-session'))->runBinariesUpdate($this->binariesRunVar());
        $this->assertFileExists(storage_path('tmux/pane-supervision.json'));

        $monitor = new class extends TmuxMonitorService
        {
            public function __construct()
            {
                $this->tmux = new class extends Tmux
                {
                    public function __construct() {}

                    public function getConstantSettings(): array
                    {
                        return [];
                    }

                    public function getMonitorSettings(): array
                    {
                        return [];
                    }

                    public function getConnectionsInfo(): array
                    {
                        return [];
                    }
                };
            }
        };
        $this->writeStatus('releases', ['cycle=1000 step=releases exit=1 start=1000 end=1001', 'cycle=1000 cycle_end=1 sleep=0 end=1001']);
        $monitor->initializeMonitor();

        $this->assertFileDoesNotExist(storage_path('tmux/pane-supervision.json'));
        $this->assertFileDoesNotExist(storage_path('tmux/panes/releases.status'), 'a cycle finished before the restart is not counted');
        file_put_contents(storage_path('tmux/pane-supervision.json'), '{not json');
        $this->assertNull((new TmuxPaneSupervisor)->summaries()['binaries']['last'], 'unreadable state reads as empty');
    }

    public function test_settings_safety_valve_and_configuration_parks_still_kill(): void
    {
        $this->fakePaneRole('binaries');
        (new TmuxTaskRunner('test-session'))->runBinariesUpdate(['settings' => ['binaries_run' => 1], 'killswitch' => ['pp' => true]]);
        $this->fakePaneRole('post_tv');
        $runner = new TmuxTaskRunner('test-session');
        $runner->runPaneTask('tv', [], ['settings' => ['post_non' => 1, 'processtvrage' => 0, 'processanime' => 0], 'counts' => ['now' => []]]);
        $runner->runPaneTask('tv', [], ['settings' => ['post_non' => 0], 'counts' => ['now' => []]]);

        foreach (['postprocess kill limit exceeded', 'no types enabled (TV/Anime)', 'disabled in settings'] as $reason) {
            Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
                && in_array('respawn-pane', $process->command, true)
                && in_array('-k', $process->command, true)
                && str_contains((string) end($process->command), $reason));
        }
    }

    /**
     * Give the task runner a settings table holding (or deliberately missing)
     * the niceness row. Every other setting it reads stays absent, which is
     * the state a fresh install's tmux pane sees before the seeder runs.
     */
    private function seedNicenessSetting(?string $value): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        if ($value !== null) {
            DB::table('settings')->insert(['name' => 'niceness', 'value' => $value]);
        }
    }

    private function fakePaneRole(string $role): void
    {
        Process::fake(function (PendingProcess $process) use ($role) {
            if (is_array($process->command) && in_array('list-panes', $process->command, true)) {
                return Process::result("%9\t{$role}\n");
            }
            if (is_array($process->command) && in_array('respawn-pane', $process->command, true)) {
                $this->respawned[] = (string) end($process->command);
            }

            return Process::result();
        });
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeStatus(string $role, array $lines): void
    {
        if (! is_dir(storage_path('tmux/panes'))) {
            mkdir(storage_path('tmux/panes'), 0755, true);
        }

        file_put_contents(storage_path("tmux/panes/{$role}.status"), implode("\n", $lines)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function binariesRunVar(int $sleep = 10): array
    {
        return ['settings' => ['binaries_run' => 1, 'bins_timer' => $sleep], 'killswitch' => ['pp' => false]];
    }

    /**
     * Swap every worker in a generated pane command for a fake that prints
     * and exits 3, and the sleep for a no-op. A bounded step keeps its
     * `timeout` prefix.
     */
    private function withFakeWorkers(string $command): string
    {
        $command = (string) preg_replace_callback(
            "/(s=\\$\\(date \\+%s\\); (?:'[^']*timeout' -k \\d+ \\d+ )?)(.*?)(; rc=\\$\\?)/",
            static fn (array $match): string => $match[1]."sh -c 'echo fake worker output; exit 3'".$match[3],
            $command,
        );

        return (string) preg_replace("/(?:nice -n-?\\d+ php '[^']*showsleep\\.php'|sleep) \\d+$/", 'true', $command);
    }

    /**
     * @return list<string>
     */
    private function respawnedCommands(): array
    {
        return $this->respawned;
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
