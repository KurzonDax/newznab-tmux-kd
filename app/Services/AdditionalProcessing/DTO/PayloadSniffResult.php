<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\PayloadClassification;

final readonly class PayloadSniffResult
{
    public function __construct(
        public PayloadClassification $classification,
        public bool $likelyFirstVolume = false,
    ) {}
}
