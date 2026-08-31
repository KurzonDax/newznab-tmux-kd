<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class DecisionReason
{
    public function __construct(
        public string $code,
        public string $description,
        public int $contribution = 0,
    ) {}

    /** @return array{code: string, description: string, contribution: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'description' => $this->description,
            'contribution' => $this->contribution,
        ];
    }
}
