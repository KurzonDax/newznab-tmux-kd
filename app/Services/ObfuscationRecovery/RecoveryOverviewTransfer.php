<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final readonly class RecoveryOverviewTransfer
{
    /** @param list<array<string,mixed>> $headers */
    public function __construct(public RecoveryTransfer $transport, public array $headers) {}
}
