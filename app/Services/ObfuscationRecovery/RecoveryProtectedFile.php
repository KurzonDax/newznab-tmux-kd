<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryProtectedFile
{
    /** @param list<array{md5:string,crc32:string}> $sliceChecks */
    public function __construct(
        public string $id,
        public string $filename,
        public int $size,
        public string $md5,
        public string $prefixMd5,
        public array $sliceChecks,
    ) {}
}
