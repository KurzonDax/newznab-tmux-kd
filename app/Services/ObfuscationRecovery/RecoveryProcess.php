<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use RuntimeException;

final readonly class RecoveryProcess
{
    public function __construct(public string $host, public int $pid, public string $started) {}

    public static function current(): self
    {
        $pid = getmypid();
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
        $namespace = @readlink('/proc/self/ns/pid');
        $stat = $pid === false ? null : self::stat($pid);
        if ($boot === false || $namespace === false || $stat === null) {
            throw new RuntimeException('worker_identity_unavailable');
        }

        return new self(hash('sha256', $boot.'|'.$namespace.'|'.gethostname()), $pid, $stat['started']);
    }

    public function provenDead(): bool
    {
        if (self::current()->host !== $this->host) {
            return false;
        }
        $stat = self::stat($this->pid);
        if ($stat !== null) {
            return $stat['started'] !== $this->started || $stat['state'] === 'Z';
        }

        return function_exists('posix_kill') && ! @posix_kill($this->pid, 0) && posix_get_last_error() === 3;
    }

    /** @return ?array{started:string,state:string} */
    private static function stat(int $pid): ?array
    {
        $raw = @file_get_contents('/proc/'.$pid.'/stat');
        if ($raw === false || ($end = strrpos($raw, ')')) === false) {
            return null;
        }
        $fields = explode(' ', substr($raw, $end + 2));
        if (! isset($fields[19]) || ! ctype_digit($fields[19])) {
            return null;
        }

        return ['started' => $fields[19], 'state' => $fields[0]];
    }
}
