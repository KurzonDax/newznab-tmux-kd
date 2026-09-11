<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Runners\OwnedProcess;
use App\Services\Runners\ReleasesRunner;

class OwnedProcessFixtureRunner extends ReleasesRunner
{
    public function __construct(private string $pidsPath, private string $nextPath)
    {
        parent::__construct();
    }

    protected function concurrencyTimeout(): int
    {
        return 2;
    }

    protected function taskForCommand(array $command): \Closure
    {
        $pids = $this->pidsPath;
        $next = $this->nextPath;

        return static fn (): string => (new self($pids, $next))->executeCommand($command);
    }

    protected function executeCommand(array|string $command): string
    {
        if ($command[3] === '2') {
            $states = [];
            foreach (json_decode(file_get_contents($this->pidsPath), true) as $pid) {
                $stat = @file_get_contents('/proc/'.$pid.'/stat');
                $states[] = $stat === false ? null : explode(' ', substr($stat, strrpos($stat, ')') + 2))[0];
            }
            file_put_contents($this->nextPath, json_encode($states));

            return 'next task completed';
        }
        file_put_contents($this->pidsPath, json_encode([getmypid()]));
        $process = new OwnedProcess([PHP_BINARY, base_path('tests/Fixtures/owned-artisan.php'), 'fixture:owned-work', $this->pidsPath]);
        $process->setTimeout(30);
        $process->run();

        return $process->getOutput();
    }
}
