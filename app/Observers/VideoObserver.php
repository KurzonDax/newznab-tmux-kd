<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Video;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Support\Facades\DB;

class VideoObserver
{
    /**
     * Handle the Video "updated" event.
     */
    public function updated(Video $video): void
    {
        DB::afterCommit(fn (): bool => $this->syncReleases($video));
    }

    /**
     * Handle the Video "deleted" event.
     */
    public function deleted(Video $video): void
    {
        DB::afterCommit(fn (): bool => $this->syncReleases($video));
    }

    private function syncReleases(Video $video): bool
    {
        ReleaseSearchIndexSync::forVideo((int) $video->id);

        return true;
    }
}
