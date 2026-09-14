<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

use RuntimeException;

final readonly class RecoveryProcess
{
    public function __construct(public string $host, public int $pid, public string $started,
        public ?string $machine = null, public ?string $boot = null, public ?string $namespace = null) {}

    public static function current(): self
    {
        $pid = getmypid();
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
        $namespace = @readlink('/proc/self/ns/pid');
        $machine = @file_get_contents('/etc/machine-id');
        $stat = $pid === false ? null : self::stat($pid);
        if ($boot === false || $namespace === false || $stat === null || $machine === false || trim($machine) === '') {
            throw new RuntimeException('worker_identity_unavailable');
        }

        return self::inDomain(hash('sha256', trim($machine).'|'.gethostname()), hash('sha256', $boot), hash('sha256', $namespace), $pid, $stat['started']);
    }

    public static function inDomain(string $machine, string $boot, string $namespace, int $pid, string $started): self
    {
        return new self(hash('sha256', $machine.'|'.$boot.'|'.$namespace), $pid, $started, $machine, $boot, $namespace);
    }

    public static function fromRow(object $row, string $prefix): self
    {
        return new self((string) $row->{$prefix.'host'}, (int) $row->{$prefix.'pid'}, (string) $row->{$prefix.'started'},
            $row->{$prefix.'machine'} ?? null, $row->{$prefix.'boot'} ?? null, $row->{$prefix.'namespace'} ?? null);
    }

    /** @return array<string,int|string|null> */
    public function columns(string $prefix): array
    {
        return [$prefix.'host' => $this->host, $prefix.'pid' => $this->pid, $prefix.'started' => $this->started,
            $prefix.'machine' => $this->machine, $prefix.'boot' => $this->boot, $prefix.'namespace' => $this->namespace];
    }

    public function provenDead(): bool
    {
        return $this->status() === 'dead';
    }

    /** @return 'dead'|'alive'|'unknown' */
    public function status(): string
    {
        $current = self::current();
        if ($this->pid < 1 || $this->started === '') {
            return 'unknown';
        }
        if ($this->machine !== null && $this->boot !== null && $this->namespace !== null) {
            if ($this->host !== self::inDomain($this->machine, $this->boot, $this->namespace, $this->pid, $this->started)->host
                || $current->machine !== $this->machine) {
                return 'unknown';
            }
            if ($current->boot !== $this->boot) {
                return 'dead';
            }
            if ($current->namespace !== $this->namespace) {
                return 'unknown';
            }
        } elseif ($this->machine !== null || $this->boot !== null || $this->namespace !== null
            || ($this->host !== $current->host && $this->host !== hash('sha256',
                file_get_contents('/proc/sys/kernel/random/boot_id').'|'.readlink('/proc/self/ns/pid').'|'.gethostname()))) {
            return 'unknown';
        }
        $stat = self::stat($this->pid);
        if ($stat !== null) {
            return $stat['started'] !== $this->started || $stat['state'] === 'Z' ? 'dead' : 'alive';
        }

        return function_exists('posix_kill') && ! @posix_kill($this->pid, 0) && posix_get_last_error() === 3 ? 'dead' : 'unknown';
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
