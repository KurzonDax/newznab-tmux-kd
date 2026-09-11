<?php

declare(strict_types=1);

namespace App\Services\Runners;

use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

/** Laravel's closure driver retains its result protocol while owning the outer process tree. */
final class OwnedProcessFactory extends Factory
{
    /** @var list<OwnedProcess> */
    private array $owned = [];

    public function own(OwnedProcess $process): void
    {
        $this->owned[] = $process;
    }

    public function stopAll(): void
    {
        foreach ($this->owned as $process) {
            $process->stop(0);
        }
        $this->owned = [];
    }

    public function newPendingProcess(): PendingProcess
    {
        return new OwnedPendingProcess($this);
    }
}
