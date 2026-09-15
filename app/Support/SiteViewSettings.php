<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SiteViewSettings
{
    public const CACHE_KEY = 'site_view_settings:v1';

    /** @var Collection<string, mixed>|null */
    private ?Collection $raw = null;

    /** @var array<string, mixed>|null */
    private ?array $converted = null;

    /** @return Collection<string, mixed> */
    public function raw(): Collection
    {
        if ($this->raw !== null) {
            return $this->raw;
        }

        try {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return $this->raw = Settings::query()->toBase()->pluck('value', 'name');
        }

        if ($cached !== null) {
            return $this->raw = collect($cached);
        }

        $this->raw = Settings::query()->toBase()->pluck('value', 'name');

        try {
            Cache::put(self::CACHE_KEY, $this->raw->all(), 300);
        } catch (Throwable) {
            // Keep the loaded snapshot when the cache write fails.
        }

        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function converted(): array
    {
        return $this->converted ??= $this->raw()->map(Settings::convertValue(...))->all();
    }
}
