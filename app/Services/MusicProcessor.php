<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MusicIdentity\ResolveReleaseMusicIdentity;

class MusicProcessor
{
    private readonly ResolveReleaseMusicIdentity $identityWorker;

    public function __construct(?ResolveReleaseMusicIdentity $identityWorker = null)
    {
        $this->identityWorker = $identityWorker ?? app(ResolveReleaseMusicIdentity::class);
    }

    public function process(string $groupID = '', string $guidChar = ''): void
    {
        $this->identityWorker->process($groupID, $guidChar);
    }
}
