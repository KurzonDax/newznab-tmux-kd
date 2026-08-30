<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity;

use App\Models\Settings;

final readonly class MusicIdentityConfiguration
{
    public bool $enabled;

    public bool $shadowMode;

    public int $workerParallelism;

    public function __construct()
    {
        $this->enabled = (int) (Settings::settingValue('music_identity_enabled') ?? 1) !== 0;
        $this->shadowMode = (int) (Settings::settingValue('music_identity_shadow') ?? 1) !== 0;
        $workers = (int) (Settings::settingValue('music_identity_workers') ?: 1);
        $maximum = max(1, (int) config('music-identity.worker_parallelism_max', 8));
        $this->workerParallelism = min($maximum, max(1, $workers));
    }

    public function active(): bool
    {
        return $this->enabled && trim((string) config('music-identity.musicbrainz.endpoint_url')) !== '';
    }
}
