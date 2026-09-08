<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryCachedPrefix
{
    /** @param list<string> $messageIds */
    public function __construct(public string $data, public bool $complete, public array $messageIds) {}
}
