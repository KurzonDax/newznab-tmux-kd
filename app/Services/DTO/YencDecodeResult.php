<?php

declare(strict_types=1);

namespace App\Services\DTO;

final readonly class YencDecodeResult
{
    public function __construct(
        public string $data,
        public bool $crcFailed = false,
    ) {}
}
