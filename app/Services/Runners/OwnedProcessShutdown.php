<?php

declare(strict_types=1);

namespace App\Services\Runners;

use Closure;
use Throwable;
use WeakMap;

/** Terminates detached work before its PHP owner exits on a terminal or shutdown signal. */
final class OwnedProcessShutdown
{
    private const array SIGNALS = [SIGHUP, SIGTERM, SIGINT];

    private static ?int $ownerPid = null;

    /** @var WeakMap<OwnedProcess, true>|null */
    private static ?WeakMap $processes = null;

    /** @var array<int, callable|int> */
    private static array $previousHandlers = [];

    private static ?Closure $handler = null;

    private static bool $previousAsync = false;

    private static bool $terminating = false;

    public static function register(OwnedProcess $process): void
    {
        pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $previousMask);
        try {
            if (self::$ownerPid !== null && self::$ownerPid !== getmypid()) {
                self::restore();
            }
            if (self::$processes === null) {
                self::$ownerPid = getmypid();
                self::$processes = new WeakMap;
                self::$previousAsync = pcntl_async_signals();
                self::$handler = static function (int $signal): void {
                    self::terminate($signal);
                };
                foreach (self::SIGNALS as $signal) {
                    self::$previousHandlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, self::$handler);
                }
                pcntl_async_signals(true);
            }
            self::$processes[$process] = true;
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $previousMask);
        }
    }

    public static function unregister(OwnedProcess $process): void
    {
        if (self::$ownerPid !== getmypid() || self::$processes === null) {
            return;
        }
        unset(self::$processes[$process]);
        if (count(self::$processes) === 0 && ! self::$terminating) {
            self::restore();
        }
    }

    private static function restore(): void
    {
        foreach (self::$previousHandlers as $signal => $handler) {
            if (pcntl_signal_get_handler($signal) === self::$handler) {
                pcntl_signal($signal, $handler);
            }
        }
        pcntl_async_signals(self::$previousAsync);
        self::$processes = null;
        self::$previousHandlers = [];
        self::$handler = null;
        self::$ownerPid = null;
        self::$terminating = false;
    }

    private static function terminate(int $signal): never
    {
        pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS);
        if (self::$ownerPid === getmypid() && self::$processes !== null) {
            self::$terminating = true;
            $processes = [];
            foreach (self::$processes as $process => $owned) {
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                try {
                    $process->stop(0);
                } catch (Throwable $exception) {
                    error_log('Owned worker shutdown failed: '.$exception->getMessage());
                }
            }
        }
        pcntl_signal($signal, SIG_DFL);
        posix_kill(getmypid(), $signal);
        pcntl_sigprocmask(SIG_UNBLOCK, [$signal]);
        exit(128 + $signal);
    }
}
