<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\Mp4MoovSpliceStatus;

final readonly class Mp4MoovSpliceResult
{
    public function __construct(
        public Mp4MoovSpliceStatus $status,
        public ?string $data = null,
    ) {}
}
