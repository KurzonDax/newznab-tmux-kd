<?php

declare(strict_types=1);

namespace App\Services\MediaInfo\Contracts;

use App\Models\MediaInfoProbe;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use Mhor\MediaInfo\Container\MediaInfoContainer;

interface MediaInfoSnapshotWriter
{
    public function capture(
        int $releaseId,
        MediaInfoContainer $container,
        MediaInfoProbeContext $context,
    ): MediaInfoProbe;
}
