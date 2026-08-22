<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\ReleaseAudioTag;
use Illuminate\Database\Eloquent\Model;

final class ReleasePreviewDataLoader
{
    /**
     * Add audio preview data to a page of release result rows in one query.
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

        $audioTagsByReleaseId = ReleaseAudioTag::query()
            ->whereIn('releases_id', array_keys($rowsByReleaseId))
            ->get([
                'releases_id',
                'audio_format',
                'has_preview',
                'preview_extension',
                'preview_mime',
                'preview_seconds',
                'has_spectrogram',
            ])
            ->keyBy('releases_id');

        foreach ($rowsByReleaseId as $releaseId => $release) {
            $audioTags = $audioTagsByReleaseId->get($releaseId);
            $audioPreviewMime = $audioTags?->playablePreviewMimeType();
            $attributes = [
                'has_spectrogram' => $audioTags?->has_spectrogram === true,
                'has_audio_preview' => $audioPreviewMime !== null,
                'audio_preview_mime' => $audioPreviewMime,
                'audio_preview_meta' => $audioPreviewMime !== null ? $audioTags->previewSummary() : null,
            ];

            if ($release instanceof Model) {
                foreach ($attributes as $attribute => $value) {
                    $release->setAttribute($attribute, $value);
                }

                continue;
            }

            foreach ($attributes as $attribute => $value) {
                $release->{$attribute} = $value;
            }
        }
    }
}
