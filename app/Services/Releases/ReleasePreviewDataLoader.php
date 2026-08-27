<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Models\ReleaseVideoClip;
use Illuminate\Database\Eloquent\Model;

final class ReleasePreviewDataLoader
{
    /**
     * Add audio and video preview data to a page of release result rows, one
     * query per artifact table.
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

        $clipsByReleaseId = ReleaseVideoClip::query()
            ->whereIn('releases_id', array_keys($rowsByReleaseId))
            ->get(['releases_id', 'extension', 'mime'])
            ->keyBy('releases_id');

        // Releases whose artifact predates Clips (or fell back to the small
        // transcode) have no metadata row; videostatus alone marks the legacy
        // OGV, and the browse queries do not select it.
        $videoReleaseIds = array_flip(
            Release::query()
                ->whereIn('id', array_keys($rowsByReleaseId))
                ->where('videostatus', 1)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        );

        foreach ($rowsByReleaseId as $releaseId => $release) {
            $audioTags = $audioTagsByReleaseId->get($releaseId);
            $audioPreviewMime = $audioTags?->playablePreviewMimeType();
            $videoPreviewMime = null;
            if (isset($videoReleaseIds[$releaseId])) {
                $videoPreviewMime = $clipsByReleaseId->get($releaseId)?->clipMimeType()
                    ?? ReleaseVideoClip::VIDEO_MIME_TYPES['ogv'];
            }
            $attributes = [
                'has_spectrogram' => $audioTags?->has_spectrogram === true,
                'has_audio_preview' => $audioPreviewMime !== null,
                'audio_preview_mime' => $audioPreviewMime,
                'audio_preview_meta' => $audioPreviewMime !== null ? $audioTags->previewSummary() : null,
                'has_video_preview' => $videoPreviewMime !== null,
                'video_preview_mime' => $videoPreviewMime,
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
