<?php

declare(strict_types=1);

namespace App\Services\Runners;

/** The closure child constructs its executor locally; no service or PDO crosses serialization. */
final class CommandRunner extends BaseRunner
{
    public function __construct(private int $timeout) {}

    /** @param list<string>|string $command */
    public function run(array|string $command): string
    {
        return $this->executeCommand($command);
    }

    protected function concurrencyTimeout(): int
    {
        return $this->timeout;
    }
}
