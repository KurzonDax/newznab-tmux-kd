<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** What {@see ReleaseNameWriter::rename()} did: the policy's decision, and whether the rename was written or refused. */
final readonly class NameWriteOutcome
{
    public function __construct(
        public NameDecision $decision,
        public bool $written = false,
        public ?NameWriteRefusal $refusal = null,
    ) {}

    /** Why the rename was not written, for an operator. */
    public function reason(): string
    {
        return $this->refusal->value ?? $this->decision->rejection->value ?? 'declined';
    }
}
