<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Gateways;

use App\Services\MusicIdentity\Exceptions\MusicBrainzGatewayException;

final class MusicBrainzRequestBudget
{
    private int $consumed = 0;

    public function __construct(private readonly int $maximum) {}

    public function consume(): void
    {
        if ($this->consumed >= max(1, $this->maximum)) {
            throw new MusicBrainzGatewayException('MusicBrainz request budget exhausted.');
        }

        $this->consumed++;
    }
}
