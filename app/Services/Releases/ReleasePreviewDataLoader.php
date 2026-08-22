<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\ReleaseAudioTag;
use Illuminate\Database\Eloquent\Model;

final class ReleasePreviewDataLoader
{
    /**
     * Add spectrogram availability to a page of release result rows in one query.
     *
     * @param  iterable<int, object>  $releases
     */
    public function load(iterable $releases): void
    {
        $rowsByReleaseId = [];

        foreach ($releases as $release) {
            $releaseId = (int) ($release->id ?? 0);
            if ($releaseId > 0) {
                $rowsByReleaseId[$releaseId] = $release;
            }
        }

        if ($rowsByReleaseId === []) {
            return;
        }

        $spectrogramReleaseIds = array_fill_keys(
            ReleaseAudioTag::query()
                ->whereIn('releases_id', array_keys($rowsByReleaseId))
                ->where('has_spectrogram', true)
                ->pluck('releases_id')
                ->map(static fn (mixed $releaseId): int => (int) $releaseId)
                ->all(),
            true,
        );

        foreach ($rowsByReleaseId as $releaseId => $release) {
            $hasSpectrogram = isset($spectrogramReleaseIds[$releaseId]);

            if ($release instanceof Model) {
                $release->setAttribute('has_spectrogram', $hasSpectrogram);

                continue;
            }

            $release->has_spectrogram = $hasSpectrogram;
        }
    }
}
