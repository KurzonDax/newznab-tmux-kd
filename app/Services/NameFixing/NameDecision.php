<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

/** What {@see NamePolicy} decided about a candidate: the name to write and how far it is trusted. */
final readonly class NameDecision
{
    private function __construct(
        public ?NameRejection $rejection,
        public string $title,
        public bool $skipsPlausibility,
        public TrustedDonor $trust,
    ) {}

    public static function accept(string $title, bool $skipsPlausibility, TrustedDonor $trust): self
    {
        return new self(null, $title, $skipsPlausibility, $trust);
    }

    public static function reject(NameRejection $rejection, string $title = ''): self
    {
        return new self($rejection, $title, false, TrustedDonor::Untrusted);
    }

    public function accepted(): bool
    {
        return $this->rejection === null;
    }
}
