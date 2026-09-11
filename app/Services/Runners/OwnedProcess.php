<?php

declare(strict_types=1);

namespace App\Services\Runners;

use FFI;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/** A private process group keeps descendants owned even after their immediate parent exits. */
final class OwnedProcess extends Process
{
    private const PR_SET_CHILD_SUBREAPER = 36;

    private ?int $processGroup = null;

    private ?int $ownerPid = null;

    private bool $stoppingTree = false;

    /** @var list<int> */
    private array $ownedGroups = [];

    /** @var list<int> */
    private array $ownedPids = [];

    private static ?int $subreaperPid = null;

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>|null  $env
     */
    public function __construct(array $command, ?string $cwd = null, ?array $env = null)
    {
        parent::__construct([
            PHP_BINARY, '-r',
            'if (posix_setsid() < 0) { exit(125); } posix_kill(getmypid(), 19); pcntl_exec($argv[1], array_slice($argv, 2)); exit(125);',
            ...$command,
        ], $cwd, $env);
    }

    /**
     * The launcher stops itself until its PID is owned, so even immediate exit cannot lose its group.
     *
     * @param  array<string, string|false>  $env
     */
    public function start(?callable $callback = null, array $env = []): void
    {
        if ($this->isRunning()) {
            throw new ProcessRuntimeException('Process is already running.');
        }
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            throw new RuntimeException('Worker process instances cannot be started by a different owner.');
        }
        if (self::$subreaperPid !== getmypid()) {
            if (PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc/self') || ! extension_loaded('ffi')
                || ! function_exists('pcntl_waitpid') || ! function_exists('posix_kill')) {
                throw new RuntimeException('Processing workers require Linux /proc and PHP FFI, pcntl, and posix.');
            }
            try {
                $libc = FFI::cdef('int prctl(int option, unsigned long arg2, unsigned long arg3, unsigned long arg4, unsigned long arg5);');
            } catch (\Throwable $exception) {
                throw new RuntimeException('Processing workers require FFI::cdef() in CLI PHP; check ffi.enable.', previous: $exception);
            }
            if ($libc->prctl(self::PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) !== 0) { // @phpstan-ignore method.notFound (libc function declared by FFI::cdef above)
                throw new RuntimeException('Unable to own orphaned worker descendants.');
            }
            self::$subreaperPid = getmypid();
        }
        $this->ownerPid = getmypid();
        OwnedProcessShutdown::register($this);
        try {
            parent::start($callback, $env);
            $this->processGroup = $this->getPid();
            $this->ownedGroups = $this->processGroup === null ? [] : [$this->processGroup];
            while ($this->processGroup !== null && $this->isRunning()) {
                $stat = @file_get_contents('/proc/'.$this->processGroup.'/stat');
                if ($stat !== false && ($end = strrpos($stat, ')')) !== false
                    && in_array(explode(' ', substr($stat, $end + 2))[0], ['T', 't'], true)) {
                    posix_kill($this->processGroup, 18);
                    break;
                }
                $this->checkTimeout();
                usleep(1000);
            }
        } catch (\Throwable $exception) {
            $this->stop(0);
            throw $exception;
        }
    }

    public function wait(?callable $callback = null): int
    {
        try {
            return parent::wait($callback);
        } finally {
            $this->stop(0);
        }
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            return null;
        }
        if ($this->stoppingTree) {
            return parent::stop($timeout, $signal);
        }
        pcntl_sigprocmask(SIG_BLOCK, [SIGHUP, SIGTERM, SIGINT], $previousMask);
        $this->stoppingTree = true;
        try {
            if ($this->processGroup !== null) {
                $this->captureDescendantGroups();
                foreach ($this->ownedGroups as $group) {
                    @posix_kill(-$group, 15);
                }
                $deadline = microtime(true) + min(max($timeout, 0), 0.2);
                while ($this->liveGroupMembers() !== [] && microtime(true) < $deadline) {
                    usleep(1000);
                }
                foreach ($this->ownedGroups as $group) {
                    @posix_kill(-$group, 9);
                }
                while ($this->liveGroupMembers() !== []) {
                    usleep(1000);
                }
            }

            $exit = parent::stop(0, $signal);
            foreach ($this->ownedPids as $pid) {
                if ($pid !== $this->processGroup) {
                    pcntl_waitpid($pid, $status);
                }
            }

            return $exit;
        } finally {
            $this->processGroup = null;
            $this->ownedGroups = [];
            $this->ownedPids = [];
            $this->stoppingTree = false;
            OwnedProcessShutdown::unregister($this);
            pcntl_sigprocmask(SIG_SETMASK, $previousMask);
        }
    }

    public function getExitCode(): ?int
    {
        $exit = parent::getExitCode();
        if ($exit !== null && ! $this->stoppingTree) {
            $this->stop(0);
        }

        return $exit;
    }

    /** @return list<int> */
    private function liveGroupMembers(): array
    {
        $members = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $stat = @file_get_contents($path);
            if ($stat === false || ($end = strrpos($stat, ')')) === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, $end + 2));
            if (in_array((int) ($fields[2] ?? 0), $this->ownedGroups, true) && ! in_array($fields[0], ['Z', 'X'], true)) {
                $members[] = (int) basename(dirname($path));
            }
        }

        return $members;
    }

    private function captureDescendantGroups(): void
    {
        $descendants = [$this->processGroup];
        @posix_kill($this->processGroup, 19);
        do {
            $added = false;
            $parents = [];
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
                $stat = @file_get_contents($path);
                if ($stat === false || ($end = strrpos($stat, ')')) === false) {
                    continue;
                }
                $fields = explode(' ', substr($stat, $end + 2));
                $pid = (int) basename(dirname($path));
                $parents[$pid] = (int) ($fields[1] ?? 0);
                if (in_array((int) ($fields[2] ?? 0), $this->ownedGroups, true) && ! in_array($pid, $descendants, true)) {
                    @posix_kill($pid, 19);
                    $descendants[] = $pid;
                    $added = true;
                }
            }
            foreach ($parents as $pid => $parent) {
                if (in_array($parent, $descendants, true) && ! in_array($pid, $descendants, true)) {
                    @posix_kill($pid, 19);
                    $descendants[] = $pid;
                    $added = true;
                }
            }
        } while ($added);
        $this->ownedGroups = array_values(array_unique([...$this->ownedGroups, ...$descendants]));
        $this->ownedPids = $descendants;
    }
}
