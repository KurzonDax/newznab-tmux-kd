<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Srrdb;

final readonly class SrrdbLookupResult
{
    public const STATUS_MATCH = 'match';

    public const STATUS_NO_MATCH = 'no_match';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public array $metadata = [],
    ) {}

    public function isMatch(): bool
    {
        return $this->status === self::STATUS_MATCH;
    }
}
