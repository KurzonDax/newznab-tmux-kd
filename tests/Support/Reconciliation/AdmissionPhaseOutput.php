<?php

declare(strict_types=1);

namespace Tests\Support\Reconciliation;

use Symfony\Component\Console\Output\Output;

/** Time the real command's existing phase messages without retaining its output. */
final class AdmissionPhaseOutput extends Output
{
    /** @var array<string, float> */
    private array $seconds = [];

    private string $phase = 'startup';

    private float $started;

    public function __construct()
    {
        parent::__construct();
        $this->started = microtime(true);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        foreach (['Finding Complete Collections' => 'completeness',
            'Calculating Collection Sizes' => 'sizing',
            'Filtering Collections by Size/File Count' => 'filtering',
            'Create releases from complete collections.' => 'creation',
            'Creating NZB Files' => 'nzb'] as $header => $phase) {
            if (str_contains($message, $header)) {
                $this->advance($phase);
                break;
            }
        }
    }

    private function advance(string $phase): void
    {
        $now = microtime(true);
        $this->seconds[$this->phase] = ($this->seconds[$this->phase] ?? 0) + $now - $this->started;
        fwrite(STDERR, 'ADMISSION_PHASE='.json_encode(['phase' => $this->phase,
            'seconds' => $this->seconds[$this->phase]], JSON_THROW_ON_ERROR).PHP_EOL);
        $this->phase = $phase;
        $this->started = $now;
    }

    /** @return array<string, float> */
    public function finish(): array
    {
        $this->advance('finished');

        return $this->seconds;
    }
}
