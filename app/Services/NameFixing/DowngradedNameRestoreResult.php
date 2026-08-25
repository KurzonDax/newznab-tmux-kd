<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

final readonly class DowngradedNameRestoreResult
{
    /**
     * @param  list<array{release_id: int, old: string, new: string}>  $pairs
     */
    public function __construct(
        public int $scanned,
        public int $restored,
        public int $skipped,
        public array $pairs,
        public bool $dryRun,
    ) {}

    /**
     * @return array{scanned: int, restored: int, skipped: int, pairs: list<array{release_id: int, old: string, new: string}>, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'restored' => $this->restored,
            'skipped' => $this->skipped,
            'pairs' => $this->pairs,
            'dry_run' => $this->dryRun,
        ];
    }
}
