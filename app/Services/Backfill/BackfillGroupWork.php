<?php

namespace App\Services\Backfill;

final readonly class BackfillGroupWork
{
    public function __construct(
        public string $name,
        public int $remaining,
        public string $targetDate,
        public string $firstRecordPostdate,
        public int $serverLastRecord,
    ) {}
}
