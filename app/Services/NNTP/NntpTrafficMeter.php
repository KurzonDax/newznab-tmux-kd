<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts the bytes read from each Usenet provider and adds them to that provider's row for
 * the day in `nntp_provider_traffic`, for the Statistics page.
 *
 * {@see self::received()} runs once per line read off a provider socket, so it only adds to an
 * in-process total and compares two floats; the database is written at most once a minute.
 * Counting must never make a download fail: a write that cannot happen leaves the bytes
 * pending for the next attempt.
 */
class NntpTrafficMeter
{
    private const float FLUSH_INTERVAL_SECONDS = 60.0;

    /** @var array<string, int> Bytes read per provider name and not yet written. */
    private array $pending = [];

    private float $lastFlush;

    private bool $warned = false;

    /**
     * @param  (Closure(): float)|null  $clock  Seconds as a float; microtime(true) when null.
     */
    public function __construct(private readonly ?Closure $clock = null)
    {
        $this->lastFlush = $this->now();

        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (Throwable) {
                // The application may already be torn down at process exit.
            }
        });
    }

    public function received(string $provider, int $bytes): void
    {
        $this->pending[$provider] = ($this->pending[$provider] ?? 0) + $bytes;

        if ($this->now() - $this->lastFlush >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flush();
        }
    }

    /**
     * Add every pending total to today's row (app timezone).
     *
     * Skipped inside a caller's transaction, so the shared daily row is never locked until
     * somebody else's commit; the first eligible read after the commit writes instead. A
     * failed write restarts the one-minute interval, so a database that is down is not
     * retried on every line read.
     */
    public function flush(): void
    {
        if ($this->pending === [] || DB::transactionLevel() > 0) {
            return;
        }

        $this->lastFlush = $this->now();

        $day = Carbon::now((string) config('app.timezone'))->toDateString();

        foreach ($this->pending as $provider => $bytes) {
            try {
                DB::table('nntp_provider_traffic')
                    ->insertOrIgnore(['provider' => $provider, 'day' => $day, 'bytes_received' => 0]);
                DB::table('nntp_provider_traffic')
                    ->where('provider', $provider)->where('day', $day)
                    ->increment('bytes_received', $bytes);
            } catch (Throwable $e) {
                $this->warnOnce($e);

                return;
            }

            unset($this->pending[$provider]);
        }
    }

    private function warnOnce(Throwable $e): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;

        try {
            Log::warning('Could not record Usenet provider download bytes; they stay pending: '.$e->getMessage());
        } catch (Throwable) {
            // No logger (a bare container): counting still must not break the download.
        }
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }
}
