<?php

declare(strict_types=1);

namespace App\Services\Runners;

use Illuminate\Process\PendingProcess;
use LogicException;

final class OwnedPendingProcess extends PendingProcess
{
    /** @param list<string>|string|null $command */
    protected function toSymfonyProcess(array|string|null $command): OwnedProcess
    {
        $command ??= $this->command;
        if (is_string($command)) {
            if (! str_ends_with($command, 'invoke-serialized-closure')) {
                throw new LogicException('The owned concurrency factory only accepts the Laravel closure wrapper.');
            }
            $command = [PHP_BINARY, base_path('artisan'), 'invoke-serialized-closure'];
        }
        $process = new OwnedProcess($command, $this->path ?? base_path(), $this->environment);
        if ($this->factory instanceof OwnedProcessFactory) {
            $this->factory->own($process);
        }
        $process->setTimeout($this->timeout);
        if ($this->idleTimeout !== null) {
            $process->setIdleTimeout($this->idleTimeout);
        }
        if ($this->input !== null) {
            $process->setInput($this->input);
        }

        return $process;
    }
}
