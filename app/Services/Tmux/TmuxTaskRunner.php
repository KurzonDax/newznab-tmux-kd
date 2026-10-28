<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\TmuxPaneRole;
use App\Models\Settings;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Service for running tasks in tmux panes
 */
class TmuxTaskRunner
{
    /**
     * Process priority every pane command runs at when the `niceness` setting
     * holds no value. Matches what the seeder plants on a fresh install, so a
     * blanked admin field behaves exactly like one that was never touched.
     */
    public const int DEFAULT_NICENESS = 19;

    /** Default for the `fix_names_timeout` setting when the row is missing. */
    public const int DEFAULT_FIX_NAMES_TIMEOUT = 1200;

    /** Floor for `fix_names_timeout`; matches the form request's `min:60`. */
    public const int MIN_FIX_NAMES_TIMEOUT = 60;

    /**
     * Grace period between `timeout` sending TERM and following up with KILL,
     * so a stuck PHP process is actually gone before the next step starts.
     */
    private const int TIMEOUT_KILL_AFTER = 10;

    protected TmuxPaneManager $paneManager;

    protected TmuxPaneSupervisor $supervisor;

    protected string $sessionName;

    public function __construct(string $sessionName)
    {
        $this->sessionName = $sessionName;
        $this->paneManager = new TmuxPaneManager($sessionName);
        $this->supervisor = new TmuxPaneSupervisor;
    }

    /**
     * Process priority for every pane command.
     *
     * The admin `niceness` setting is the only tuning surface. A missing row
     * reads as null and a blanked field as an empty string; both fall back to
     * the canonical default. A stored `0` is a real value and is honored.
     */
    protected function getNiceness(): int
    {
        $niceness = Settings::settingValue('niceness');

        if ($niceness === null || $niceness === '') {
            return self::DEFAULT_NICENESS;
        }

        return (int) $niceness;
    }

    /**
     * Run a task in a specific pane
     *
     * @param  array<string, mixed>  $config
     */
    public function runTask(string $taskName, array $config): bool
    {
        $role = $config['role'] ?? null;
        $command = $config['command'] ?? null;
        $enabled = $config['enabled'] ?? true;
        $workAvailable = $config['work_available'] ?? true;

        if (! $role instanceof TmuxPaneRole || ! $command) {
            return false;
        }

        $legacyPane = $config['legacy_pane'] ?? null;
        $pane = $this->paneManager->paneForRole($role, is_string($legacyPane) ? $legacyPane : null);

        // Check if task is enabled and has work
        if (! $enabled) {
            return $this->disablePane($pane, $taskName, 'disabled in settings');
        }

        if (! $workAvailable) {
            return $this->disablePane($pane, $taskName, 'no work available', kill: false);
        }

        // Respawn the pane with the command
        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Disable a pane with a message.
     *
     * `kill` stops whatever the pane is running. Without it tmux refuses while
     * a worker is still busy, so a "no work" park waits for the batch to end
     * and the next pass parks the finished pane.
     */
    protected function disablePane(string $pane, string $taskName, string $reason, bool $kill = true): bool
    {
        return $this->parkPane($pane, "{$taskName} has been disabled: {$reason}", $kill);
    }

    /**
     * A pane disabled in settings is parked, killing any running worker, and
     * starts its supervision history clean.
     */
    protected function disableInSettings(string $pane, TmuxPaneRole $role, string $taskName): bool
    {
        $this->supervisor->forget($role);

        return $this->disablePane($pane, $taskName, 'disabled in settings');
    }

    /**
     * Park a supervised pane that is waiting out its backoff. Returns null
     * when the pane may launch.
     */
    protected function parkWhileBackingOff(string $pane, TmuxPaneRole $role, string $taskName): ?bool
    {
        $message = $this->supervisor->backoffMessage($role, $taskName);

        return $message === null ? null : $this->parkPane($pane, $message, kill: false);
    }

    private function parkPane(string $pane, string $message, bool $kill): bool
    {
        $color = $this->getRandomColor();
        $message = addcslashes($message, '\\"$`');

        return $this->paneManager->respawnPane($pane, "echo -e \"\033[38;5;{$color}m\n{$message}\"", kill: $kill);
    }

    /**
     * Build a command with logging
     *
     * @param  array<string, mixed>  $options
     */
    public function buildCommand(string $baseCommand, array $options = []): string
    {
        if (isset($options['log_pane'])) {
            $logFile = $this->getLogFile($options['log_pane']);

            return "{$baseCommand} 2>&1 | tee -a {$logFile}";
        }

        return $baseCommand;
    }

    /**
     * One step of a supervised pane, with its output logged and its result
     * appended to the role's status file.
     *
     * The step's exit status is taken inside the braces, before the pipe to
     * `tee` replaces it, and every later check reads `$rc`, never `$?`. The
     * worker writes its failed/total pool children to the file named by
     * {@see TmuxPaneSupervisor::CHILDREN_ENV}; the status line carries them.
     * `$c` is the cycle id {@see self::supervisedCycle()} sets.
     */
    protected function supervisedStep(
        TmuxPaneRole $role,
        string $label,
        string $command,
        string $log,
        ?string $timeoutNotice = null,
    ): string {
        $children = escapeshellarg($this->supervisor->childrenFile($role));
        $status = escapeshellarg($this->supervisor->statusFile($role));
        $timeoutCheck = $timeoutNotice === null ? '' : "case \$rc in 124|137) echo \"{$timeoutNotice}\";; esac; ";
        $bounded = $timeoutNotice === null ? '' : ' bounded=1';

        return '{ export '.TmuxPaneSupervisor::CHILDREN_ENV."={$children}; rm -f {$children}; s=\$(date +%s); "
            ."{$command}; rc=\$?; {$timeoutCheck}"
            ."k=; if [ -f {$children} ]; then k=\" children_failed=\$(cat {$children})\"; rm -f {$children}; fi; "
            ."printf '%s\\n' \"cycle=\$c step=\"".escapeshellarg($label)."\" exit=\$rc start=\$s end=\$(date +%s){$bounded}\$k\" >> {$status}; "
            .'} 2>&1 | tee -a '.escapeshellarg($log);
    }

    /**
     * A supervised pane's whole command: the cycle id, its steps, the
     * `cycle_end` line recording this cycle's sleep, then the timestamp and
     * the sleep outside any step.
     *
     * @param  list<string>  $parts  steps from {@see self::supervisedStep()}, plus any plain notices
     */
    protected function supervisedCycle(TmuxPaneRole $role, array $parts, int $sleep): string
    {
        $this->supervisor->ensureDirectory();
        $status = escapeshellarg($this->supervisor->statusFile($role));

        return 'c=$(date +%s); '.implode('; ', $parts)
            ."; printf '%s\\n' \"cycle=\$c cycle_end=1 sleep={$sleep} end=\$(date +%s)\" >> {$status}"
            ."; date +'%Y-%m-%d %T'; ".$this->buildSleepCommand($sleep);
    }

    /**
     * A supervised cycle of plain steps, all logged to one pane log.
     *
     * @param  array<string, string>  $steps  label => command
     */
    protected function supervisedCommand(TmuxPaneRole $role, array $steps, string $log, int $sleep): string
    {
        $parts = [];
        foreach ($steps as $label => $command) {
            $parts[] = $this->supervisedStep($role, (string) $label, $command, $log);
        }

        return $this->supervisedCycle($role, $parts, $sleep);
    }

    /**
     * Build sleep command
     */
    protected function buildSleepCommand(int $seconds): string
    {
        $niceness = $this->getNiceness();
        $sleepScript = base_path('app/Services/Tmux/Scripts/showsleep.php');

        if (file_exists($sleepScript)) {
            return "nice -n{$niceness} php {$sleepScript} {$seconds}";
        }

        return "sleep {$seconds}";
    }

    /**
     * Get log file path for a pane
     */
    protected function getLogFile(string $paneName): string
    {
        if (! config('tmux.write_logs')) {
            return '/dev/null';
        }

        $logDir = config('tmux.paths.logs', storage_path('logs/tmux'));

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $date = now()->format('Y_m_d');

        return "{$logDir}/{$paneName}-{$date}.log";
    }

    /**
     * Get a random color for terminal output
     */
    protected function getRandomColor(): int
    {
        $start = (int) Settings::settingValue('colors_start');
        $end = (int) Settings::settingValue('colors_end');
        $exclude = Settings::settingValue('colors_exc') ?? '';

        if (empty($exclude)) {
            return random_int($start, $end);
        }

        $exceptions = array_map('intval', explode(',', $exclude));
        sort($exceptions);

        $number = random_int($start, $end - count($exceptions));

        foreach ($exceptions as $exception) {
            if ($number >= $exception) {
                $number++;
            } else {
                break;
            }
        }

        return $number;
    }

    /**
     * Run the IRC scraper
     *
     * @param  array<string, mixed>  $config
     */
    public function runIRCScraper(array $config): bool
    {
        $runScraper = (int) ($config['constants']['run_ircscraper'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::IrcScraper, '3.0');

        if ($runScraper !== 1) {
            return $this->disablePane($pane, 'IRC Scraper', 'disabled in settings');
        }

        $niceness = $this->getNiceness();
        $artisan = base_path('artisan');
        $command = "nice -n{$niceness} php {$artisan} irc:scrape";
        $command = $this->buildCommand($command, ['log_pane' => 'scraper']);

        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Run binaries update
     *
     * @param  array<string, mixed>  $config
     */
    public function runBinariesUpdate(array $config): bool
    {
        $enabled = (int) ($config['settings']['binaries_run'] ?? 0);
        $killswitch = $config['killswitch']['pp'] ?? false;
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::Binaries, '0.1');
        $this->supervisor->observe(TmuxPaneRole::Binaries);

        if (! $enabled) {
            return $this->disableInSettings($pane, TmuxPaneRole::Binaries, 'Update Binaries');
        }

        if ($killswitch) {
            return $this->disablePane($pane, 'Update Binaries', 'postprocess kill limit exceeded');
        }

        $artisanCommand = match ((int) $enabled) {
            1 => 'multiprocessing:safe binaries',
            default => null,
        };

        if (! $artisanCommand) {
            return false;
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::Binaries, 'Update Binaries')) !== null) {
            return $parked;
        }

        $niceness = $this->getNiceness();
        $command = "nice -n{$niceness} ".PHP_BINARY." artisan {$artisanCommand}";
        $sleep = (int) ($config['settings']['bins_timer'] ?? 60);
        $command = $this->supervisedCommand(TmuxPaneRole::Binaries, ['binaries' => $command], $this->getLogFile('binaries'), $sleep);

        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Run backfill
     *
     * @param  array<string, mixed>  $config
     */
    public function runBackfill(array $config): bool
    {
        $enabled = (int) ($config['settings']['backfill'] ?? 0);
        $collKillswitch = $config['killswitch']['coll'] ?? false;
        $ppKillswitch = $config['killswitch']['pp'] ?? false;
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::Backfill, '0.2');
        $this->supervisor->observe(TmuxPaneRole::Backfill);

        if (! $enabled) {
            return $this->disableInSettings($pane, TmuxPaneRole::Backfill, 'Backfill');
        }

        if ($collKillswitch || $ppKillswitch) {
            return $this->disablePane($pane, 'Backfill', 'kill limit exceeded');
        }

        $artisanCommand = match ((int) $enabled) {
            1 => 'multiprocessing:backfill',
            default => null,
        };

        if (! $artisanCommand) {
            return false;
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::Backfill, 'Backfill')) !== null) {
            return $parked;
        }

        // Calculate sleep time (progressive if enabled). intdiv() keeps this an
        // int: floor() returns a float, which buildSleepCommand()'s int
        // parameter rejects under strict_types the moment the branch engages.
        $baseSleep = (int) ($config['settings']['back_timer'] ?? 600);
        $collections = (int) ($config['counts']['now']['collections_table'] ?? 0);
        $progressive = (int) ($config['settings']['progressive'] ?? 0);

        $sleep = $baseSleep;
        if ($progressive === 1) {
            $progressiveSleep = intdiv(max(0, $collections), 500);
            if ($progressiveSleep > $baseSleep) {
                $sleep = $progressiveSleep;
            }
        }

        $niceness = $this->getNiceness();
        $command = "nice -n{$niceness} ".PHP_BINARY." artisan {$artisanCommand}";
        $command = $this->supervisedCommand(TmuxPaneRole::Backfill, ['backfill' => $command], $this->getLogFile('backfill'), $sleep);

        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Run releases update
     *
     * @param  array<string, mixed>  $config
     */
    public function runReleasesUpdate(array $config): bool
    {
        $enabled = (int) ($config['settings']['releases_run'] ?? 0);
        $legacyPane = (int) ($config['constants']['sequential'] ?? 0) === 1 ? '0.1' : '0.3';
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::Releases, $legacyPane);
        $this->supervisor->observe(TmuxPaneRole::Releases);

        if (! $enabled) {
            return $this->disableInSettings($pane, TmuxPaneRole::Releases, 'Update Releases');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::Releases, 'Update Releases')) !== null) {
            return $parked;
        }

        $niceness = $this->getNiceness();
        $command = "nice -n{$niceness} ".PHP_BINARY.' artisan multiprocessing:releases';
        $sleep = (int) ($config['settings']['rel_timer'] ?? 60);
        $command = $this->supervisedCommand(TmuxPaneRole::Releases, ['releases' => $command], $this->getLogFile('releases'), $sleep);

        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Run a specific pane task based on task name
     *
     * @param  string  $taskName  The name of the task to run
     * @param  array<string, mixed>  $config  Configuration for the task (target pane, etc.)
     * @param  array<string, mixed>  $runVar  Runtime variables and settings
     * @return bool Success status
     */
    public function runPaneTask(string $taskName, array $config, array $runVar): bool
    {
        $sequential = (int) ($runVar['constants']['sequential'] ?? 0);

        return match ($taskName) {
            'main' => $this->runMainTask($sequential, $runVar),
            'fixnames' => $this->runFixNamesTask($runVar),
            'removecrap' => $this->runRemoveCrapTask($runVar),
            'ppadditional' => $this->runPostProcessAdditional($runVar),
            'tv' => $this->runTvTask($runVar),
            'movies' => $this->runMoviesTask($runVar),
            'amazon' => $this->runAmazonTask($runVar),
            'recovery_local' => $this->runRecovery(false),
            'recovery_download' => $this->runRecovery(true),
            'scraper' => $this->runIRCScraper($runVar),
            // Legacy mapping for backward compatibility
            'nonamazon' => $this->runTvTask($runVar),
            default => false,
        };
    }

    private function runRecovery(bool $download): bool
    {
        $role = $download ? TmuxPaneRole::RecoveryDownload : TmuxPaneRole::RecoveryLocal;
        $pane = $this->paneManager->paneForRole($role);
        $this->supervisor->observe($role);
        $taskName = $download ? 'Recovery Downloads' : 'Recovery Local';

        if (($parked = $this->parkWhileBackingOff($pane, $role, $taskName)) !== null) {
            return $parked;
        }

        $artisan = 'nice -n'.$this->getNiceness().' php '.escapeshellarg(base_path('artisan'));
        $steps = [];
        foreach ($download ? ['download'] : ['discover', 'publish'] as $step) {
            $steps[$step] = $artisan.' obfuscation:'.$step.' --engine';
        }
        $command = $this->supervisedCommand($role, $steps, $this->getLogFile($role->value), 5);

        return $this->paneManager->respawnPane($pane, $command);
    }

    /**
     * Run main task (varies by sequential mode)
     *
     * Anything other than basic sequential -- including a legacy hand-set 2 --
     * takes the full-mode path, which is the layout the engine builds for it.
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runMainTask(int $sequential, array $runVar): bool
    {
        return match ($sequential) {
            1 => $this->runMainBasic($runVar),
            default => $this->runMainNonSequential($runVar),
        };
    }

    /**
     * Run main non-sequential task (binaries, backfill, releases)
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runMainNonSequential(array $runVar): bool
    {
        // This runs in pane 0.1, 0.2, 0.3
        // For now, delegate to existing methods
        $this->runBinariesUpdate($runVar);
        $this->runBackfill($runVar);
        $this->runReleasesUpdate($runVar);

        return true;
    }

    /**
     * Run main basic sequential task (just releases)
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runMainBasic(array $runVar): bool
    {
        return $this->runReleasesUpdate($runVar);
    }

    /**
     * Run fix release names task
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runFixNamesTask(array $runVar): bool
    {
        $enabled = (int) ($runVar['settings']['fix_names'] ?? 0);
        $work = (int) ($runVar['counts']['now']['name_work_available'] ?? $runVar['counts']['now']['processrenames'] ?? 0)
            + (int) ($runVar['counts']['now']['processpredbft'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::FixNames, '1.0');
        $this->supervisor->observe(TmuxPaneRole::FixNames);

        if ($enabled !== 1) {
            return $this->disableInSettings($pane, TmuxPaneRole::FixNames, 'Fix Release Names');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::FixNames, 'Fix Release Names')) !== null) {
            return $parked;
        }

        if ($work === 0) {
            return $this->disablePane($pane, 'Fix Release Names', 'no releases to process', kill: false);
        }

        $artisan = base_path('artisan');
        $log = $this->getLogFile('fixnames');
        $timeout = $this->fixNamesTimeout($runVar);

        // The odd methods look at the last 6 hours only; the standard sweep that
        // follows has no window and covers every one of their sources -- SRRDB
        // included, under the same config gate as level 21 -- so anything a
        // stalled method let age out is still picked up on a later cycle.
        $steps = [];
        if ($this->timeoutBinary() === null) {
            $steps[] = "echo 'fix_names_timeout is not enforced: coreutils timeout (or gtimeout) was not found on PATH' | tee -a {$log}";
        }

        $levels = [3, 5, 7, 9, 11, 13, 15, 17, 19];
        if (config('nntmux_srrdb.enabled', false)) {
            $levels[] = 21;
        }

        foreach ($levels as $level) {
            $steps[] = $this->boundedStep(
                TmuxPaneRole::FixNames,
                "fix-names {$level}",
                "php {$artisan} releases:fix-names {$level} --update --category=other --set-status --show",
                $timeout,
                $log
            );
        }

        $steps[] = $this->boundedStep(
            TmuxPaneRole::FixNames,
            'fix-names standard sweep',
            "php {$artisan} multiprocessing:fixrelnames standard",
            $timeout,
            $log
        );

        $steps[] = $this->boundedStep(
            TmuxPaneRole::FixNames,
            'fix-names predb full-text sweep',
            "php {$artisan} multiprocessing:fixrelnames predbft",
            $timeout,
            $log
        );

        $sleep = (int) ($runVar['settings']['fix_timer'] ?? 300);

        return $this->paneManager->respawnPane($pane, $this->supervisedCycle(TmuxPaneRole::FixNames, $steps, $sleep));
    }

    /**
     * One supervised step of a sequential pane chain, bounded by a wall-clock
     * limit.
     *
     * The command runs under coreutils `timeout`, which TERMs it at the limit
     * and KILLs it shortly after, and a single line naming the step is written
     * when that happens. The signal goes to the command's whole process group,
     * so the sweep's forked `groupfixrelnames.php` workers die with it. Such a
     * step is recorded with `bounded=1`, and its 124/137 exit counts as moving
     * on, not as a failure. When no `timeout` binary is available the command
     * runs unbounded; {@see self::runFixNamesTask()} says so in the pane once
     * per cycle.
     */
    public function boundedStep(TmuxPaneRole $role, string $label, string $command, int $timeoutSeconds, string $log): string
    {
        $timeoutBinary = $this->timeoutBinary();
        if ($timeoutBinary === null) {
            return $this->supervisedStep($role, $label, $command, $log);
        }

        $killAfter = self::TIMEOUT_KILL_AFTER;
        $notice = "\$(date +'%Y-%m-%d %T') {$label} timed out after {$timeoutSeconds}s, moving on";

        return $this->supervisedStep($role, $label, "{$timeoutBinary} -k {$killAfter} {$timeoutSeconds} {$command}", $log, $notice);
    }

    /**
     * @param  array<string, mixed>  $runVar
     */
    protected function fixNamesTimeout(array $runVar): int
    {
        $configured = (int) ($runVar['settings']['fix_names_timeout'] ?? self::DEFAULT_FIX_NAMES_TIMEOUT);

        return max(self::MIN_FIX_NAMES_TIMEOUT, $configured);
    }

    /**
     * Path of the coreutils `timeout` binary (`gtimeout` where Homebrew
     * installs it unprefixed), or null when the host has neither.
     */
    protected function timeoutBinary(): ?string
    {
        $finder = new ExecutableFinder;

        return $finder->find('timeout') ?? $finder->find('gtimeout');
    }

    /**
     * Run remove crap releases task
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runRemoveCrapTask(array $runVar): bool
    {
        $option = $runVar['settings']['fix_crap_opt'] ?? 'Disabled';
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::RemoveCrap, '1.1');
        $this->supervisor->observe(TmuxPaneRole::RemoveCrap);

        // Handle disabled state
        if ($option === 'Disabled' || $option === 0 || $option === '0') {
            return $this->disableInSettings($pane, TmuxPaneRole::RemoveCrap, 'Remove Crap');
        }

        if ($option !== 'All' && $option !== 'Custom') {
            return $this->disablePane($pane, 'Remove Crap', 'invalid configuration');
        }

        $types = [];
        if ($option === 'Custom') {
            $selectedTypes = $runVar['settings']['fix_crap'] ?? '';

            // Convert numeric 0 or empty values to empty string
            if (empty($selectedTypes)) {
                return $this->disablePane($pane, 'Remove Crap', 'no crap types selected');
            }

            $types = is_array($selectedTypes) ? $selectedTypes : explode(',', $selectedTypes);

            // Trim whitespace and filter out empty values and '0'
            $types = array_map('trim', $types);
            $types = array_filter($types, fn ($type) => ! empty($type));

            // Re-index array to ensure sequential keys
            $types = array_values($types);

            if (empty($types)) {
                return $this->disablePane($pane, 'Remove Crap', 'no crap types selected');
            }
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::RemoveCrap, 'Remove Crap')) !== null) {
            return $parked;
        }

        $niceness = $this->getNiceness();
        $artisan = base_path('artisan');
        $sleep = (int) ($runVar['settings']['crap_timer'] ?? 300);
        $log = $this->getLogFile('removecrap');

        // Handle 'All' mode - run all types with 2 hour time limit
        if ($option === 'All') {
            $command = "nice -n{$niceness} php {$artisan} releases:remove-crap --time=2 --delete";
            $command = $this->supervisedCommand(TmuxPaneRole::RemoveCrap, ['all' => $command], $log, $sleep);

            return $this->paneManager->respawnPane($pane, $command);
        }

        // 'Custom' mode - run all selected types sequentially
        // Get state to determine if this is first run
        $stateFile = storage_path('tmux/removecrap_state.json');
        $state = $this->loadCrapState($stateFile);
        $isFirstRun = $state['first_run'] ?? true;

        // Determine time limit: full on first run, 4 hours otherwise
        $time = $isFirstRun ? 'full' : '4';

        // Build commands for all enabled types to run sequentially
        $commands = [];
        foreach ($types as $type) {
            $commands[] = "echo \"\nRunning removeCrapReleases for {$type}\"";
            $commands[] = $this->supervisedStep(
                TmuxPaneRole::RemoveCrap,
                (string) $type,
                "nice -n{$niceness} php {$artisan} releases:remove-crap --type={$type} --time={$time} --delete",
                $log,
            );
        }

        $fullCommand = $this->supervisedCycle(TmuxPaneRole::RemoveCrap, $commands, $sleep);

        // Mark that we're not on the first run anymore for next cycle
        $this->saveCrapState($stateFile, [
            'first_run' => false,
            'types' => $types,
        ]);

        return $this->paneManager->respawnPane($pane, $fullCommand);
    }

    /**
     * Load crap removal state
     *
     * @return array<string, mixed>
     */
    protected function loadCrapState(string $file): array
    {
        if (! file_exists($file)) {
            return ['first_run' => true];
        }

        $content = file_get_contents($file);
        $state = json_decode($content, true);

        return $state ?: ['first_run' => true];
    }

    /**
     * Save crap removal state
     *
     * @param  array<string, mixed>  $state
     */
    protected function saveCrapState(string $file, array $state): void
    {
        $dir = dirname($file);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * Run post-process additional task
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runPostProcessAdditional(array $runVar): bool
    {
        $postSetting = (int) ($runVar['settings']['post'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::PostAdditional, '2.0');
        $this->supervisor->observe(TmuxPaneRole::PostAdditional);

        // Check if post processing is enabled (1 = additional, 2 = nfo, 3 = both)
        if ($postSetting === 0) {
            return $this->disableInSettings($pane, TmuxPaneRole::PostAdditional, 'Post-process Additional');
        }

        if (! in_array($postSetting, [1, 2, 3], true)) {
            return $this->disablePane($pane, 'Post-process Additional', 'invalid post setting value');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::PostAdditional, 'Post-process Additional')) !== null) {
            return $parked;
        }

        $hasWork = (int) ($runVar['counts']['now']['work_available'] ?? $runVar['counts']['now']['work'] ?? 0) > 0;
        $hasNfo = (int) ($runVar['counts']['now']['processnfo'] ?? 0) > 0;

        $niceness = $this->getNiceness();
        $sleep = (int) ($runVar['settings']['post_timer'] ?? 300);
        $worker = "nice -n{$niceness} ".PHP_BINARY.' artisan multiprocessing:postprocess';

        // Post = 1: additional only, 2: NFO only, 3: both
        $steps = [];
        if ($postSetting !== 2 && $hasWork) {
            $steps['add'] = "{$worker} add";
        }
        if ($postSetting !== 1 && $hasNfo) {
            $steps['nfo'] = "{$worker} nfo";
        }

        // If no work available, park the pane once the running batch ends
        if ($steps === []) {
            $reason = match ($postSetting) {
                1 => 'no additional work to process',
                2 => 'no NFOs to process',
                default => 'no additional work or NFOs to process',
            };

            return $this->disablePane($pane, 'Post-process Additional', $reason, kill: false);
        }

        $fullCommand = $this->supervisedCommand(TmuxPaneRole::PostAdditional, $steps, $this->getLogFile('post_additional'), $sleep);

        return $this->paneManager->respawnPane($pane, $fullCommand);
    }

    /**
     * Run TV/Anime post-processing
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runTvTask(array $runVar): bool
    {
        $enabled = (int) ($runVar['settings']['post_non'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::PostTv, '2.1');
        $this->supervisor->observe(TmuxPaneRole::PostTv);

        if ($enabled !== 1) {
            return $this->disableInSettings($pane, TmuxPaneRole::PostTv, 'Post-process TV/Anime');
        }

        $processTv = (int) ($runVar['settings']['processtvrage'] ?? 0);
        $processAnime = (int) ($runVar['settings']['processanime'] ?? 0);
        $enabledTypes = [];
        if ($processTv > 0) {
            $enabledTypes[] = 'TV';
        }
        if ($processAnime > 0) {
            $enabledTypes[] = 'Anime';
        }

        if (empty($enabledTypes)) {
            return $this->disablePane($pane, 'Post-process TV/Anime', 'no types enabled (TV/Anime)');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::PostTv, 'Post-process TV/Anime')) !== null) {
            return $parked;
        }

        $niceness = $this->getNiceness();
        $artisan = PHP_BINARY.' artisan';
        $steps = [];

        // TV processing - Check work count before adding to queue
        $hasTvWork = (int) ($runVar['counts']['now']['processtv'] ?? 0) > 0;
        if ($processTv > 0 && $hasTvWork) {
            $steps['tv'] = "nice -n{$niceness} {$artisan} multiprocessing:postprocess tv";
        }

        // Anime processing
        $hasAnimeWork = (int) ($runVar['counts']['now']['processanime'] ?? 0) > 0;
        if ($processAnime > 0 && $hasAnimeWork) {
            $steps['ani'] = "nice -n{$niceness} {$artisan} multiprocessing:postprocess ani";
        }

        // If no work available for any enabled type, park the pane once the running batch ends
        if ($steps === []) {
            $typesList = implode(', ', $enabledTypes);

            return $this->disablePane($pane, 'Post-process TV/Anime', "no work for enabled types ({$typesList})", kill: false);
        }

        $sleep = (int) ($runVar['settings']['post_timer_non'] ?? 300);
        $fullCommand = $this->supervisedCommand(TmuxPaneRole::PostTv, $steps, $this->getLogFile('post_tv'), $sleep);

        return $this->paneManager->respawnPane($pane, $fullCommand);
    }

    /**
     * Run Movies post-processing
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runMoviesTask(array $runVar): bool
    {
        $enabled = (int) ($runVar['settings']['post_non'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::PostMovies, '2.3');
        $this->supervisor->observe(TmuxPaneRole::PostMovies);

        if ($enabled !== 1) {
            return $this->disableInSettings($pane, TmuxPaneRole::PostMovies, 'Post-process Movies');
        }

        $niceness = $this->getNiceness();
        $artisan = PHP_BINARY.' artisan';

        // Movies processing - Uses single-process command
        $processMovies = (int) ($runVar['settings']['processmovies'] ?? 0);
        $hasMoviesWork = (int) ($runVar['counts']['now']['processmovies'] ?? 0) > 0;

        if ($processMovies === 0) {
            return $this->disableInSettings($pane, TmuxPaneRole::PostMovies, 'Post-process Movies');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::PostMovies, 'Post-process Movies')) !== null) {
            return $parked;
        }

        if (! $hasMoviesWork) {
            return $this->disablePane($pane, 'Post-process Movies', 'no work available', kill: false);
        }

        $sleep = (int) ($runVar['settings']['post_timer_non'] ?? 300);
        $command = "nice -n{$niceness} {$artisan} multiprocessing:postprocess mov false";
        $fullCommand = $this->supervisedCommand(TmuxPaneRole::PostMovies, ['mov' => $command], $this->getLogFile('post_movies'), $sleep);

        return $this->paneManager->respawnPane($pane, $fullCommand);
    }

    /**
     * Legacy method for backward compatibility - now just calls runTvTask
     *
     * @deprecated Use runTvTask() instead
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runNonAmazonTask(array $runVar): bool
    {
        return $this->runTvTask($runVar);
    }

    /**
     * Run metadata post-processing (Books, Music, Games, Console)
     *
     * @param  array<string, mixed>  $runVar
     */
    protected function runAmazonTask(array $runVar): bool
    {
        $enabled = (int) ($runVar['settings']['post_amazon'] ?? 0);
        $pane = $this->paneManager->paneForRole(TmuxPaneRole::PostMetadata, '2.2');
        $this->supervisor->observe(TmuxPaneRole::PostMetadata);

        if ($enabled !== 1) {
            return $this->disableInSettings($pane, TmuxPaneRole::PostMetadata, 'Post-process Metadata');
        }

        if (($parked = $this->parkWhileBackingOff($pane, TmuxPaneRole::PostMetadata, 'Post-process Metadata')) !== null) {
            return $parked;
        }

        $hasMetadataWork = (int) ($runVar['counts']['now']['processmusic'] ?? 0) > 0
            || (int) ($runVar['counts']['now']['processbooks'] ?? 0) > 0
            || (int) ($runVar['counts']['now']['processconsole'] ?? 0) > 0
            || (int) ($runVar['counts']['now']['processgames'] ?? 0) > 0;

        // Audio previews share this pane rather than 2.0: metadata lookups here
        // use no NNTP connection, so there is headroom, and 2.0 already carries
        // the whole video backlog.
        $hasAudioWork = (int) ($runVar['counts']['now']['audio_work_available'] ?? 0) > 0;

        if (! $hasMetadataWork && ! $hasAudioWork) {
            return $this->disablePane($pane, 'Post-process Metadata', 'no music/books/games or audio previews to process', kill: false);
        }

        $niceness = $this->getNiceness();
        $artisan = PHP_BINARY.' artisan';
        $sleep = (int) ($runVar['settings']['post_timer_amazon'] ?? 300);

        $steps = [];
        if ($hasMetadataWork) {
            $steps['ama'] = "nice -n{$niceness} {$artisan} multiprocessing:postprocess ama";
        }
        if ($hasAudioWork) {
            $steps['aud'] = "nice -n{$niceness} {$artisan} multiprocessing:postprocess aud";
        }

        $fullCommand = $this->supervisedCommand(TmuxPaneRole::PostMetadata, $steps, $this->getLogFile('post_amazon'), $sleep);

        return $this->paneManager->respawnPane($pane, $fullCommand);
    }
}
