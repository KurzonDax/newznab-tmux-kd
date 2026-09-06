<?php

declare(strict_types=1);

namespace App\Services\MediaInfo;

use App\Models\AudioData;
use App\Models\MediaInfo;
use App\Models\Release;
use App\Models\ReleaseAudioTag;
use App\Models\ReleaseSubtitle;
use App\Models\VideoData;
use App\Support\ReleaseDisplayNameFormatter;

final class MediaInfoPresentationService
{
    public function __construct(
        private readonly MediaInfoSnapshotService $snapshots = new MediaInfoSnapshotService,
    ) {}

    /**
     * @return array{release_name: string, media: array<string, mixed>|null}
     */
    public function forRelease(Release $release): array
    {
        $releaseId = (int) $release->id;
        $snapshot = $this->snapshots->selectedForRelease($releaseId);

        return [
            'release_name' => ReleaseDisplayNameFormatter::displayFor($release),
            'media' => $snapshot === null
                ? $this->legacyMedia($releaseId)
                : $this->snapshotMedia($snapshot->container, $snapshot->streams),
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>
     */
    private function snapshotMedia(array $container, array $streams): array
    {
        $musicTags = $this->filledArray($container['music_tags'] ?? null);
        $embeddedTitle = $this->filledString($container['embedded_title'] ?? null);
        $groupedStreams = $this->groupStreams($streams);
        $trackTitle = $this->filledString($musicTags['track_title'] ?? null);
        $isMusic = $musicTags !== null && ($trackTitle !== null || $groupedStreams['video'] === []);

        return [
            'identity' => $this->identity($isMusic ? $trackTitle : $embeddedTitle, $isMusic),
            'container' => [
                'source_filename' => $this->filledString($container['source_filename'] ?? null),
                'format' => $this->filledString($container['format'] ?? null),
                'duration_ms' => $container['duration_ms'] ?? null,
                'overall_bitrate_bps' => $container['overall_bitrate_bps'] ?? null,
            ],
            'music_tags' => $musicTags,
            'streams' => $groupedStreams,
        ];
    }

    /** @return array<string, mixed>|null */
    private function legacyMedia(int $releaseId): ?array
    {
        $general = MediaInfo::query()->where('releases_id', $releaseId)->first();
        $video = VideoData::query()->where('releases_id', $releaseId)->first();
        $audios = AudioData::query()->where('releases_id', $releaseId)->orderBy('id')->get();
        $subtitles = ReleaseSubtitle::query()->where('releases_id', $releaseId)->orderBy('id')->get();
        $audioTags = ReleaseAudioTag::query()->where('releases_id', $releaseId)->first();
        $musicTags = $this->legacyMusicTags($audioTags);

        if ($general === null && $video === null && $audios->isEmpty() && $subtitles->isEmpty() && $musicTags === null) {
            return null;
        }

        $embeddedTitle = $this->filledString($musicTags['track_title'] ?? null)
            ?? $this->filledString($general?->movie_name);
        $streams = [
            'video' => $video === null ? [] : [[
                'type' => 'video',
                'index' => 0,
                'id' => null,
                'order' => null,
                'title' => null,
                'language' => null,
                'format' => $this->filledString($video->videoformat),
                'codec' => $this->filledString($video->videocodec),
                'default' => null,
                'forced' => null,
                'duration_display' => $this->filledString($video->videoduration),
                'bitrate_display' => $this->filledString($video->overallbitrate),
                'width' => $video->videowidth === null ? null : (int) $video->videowidth,
                'height' => $video->videoheight === null ? null : (int) $video->videoheight,
                'aspect_ratio' => $this->filledString($video->videoaspect),
                'frame_rate' => $video->videoframerate === null ? null : (float) $video->videoframerate,
                'profile' => $this->filledString($video->videolibrary),
            ]],
            'audio' => $audios->values()->map(fn (AudioData $audio, int $index): array => [
                'type' => 'audio',
                'index' => $index,
                'id' => $audio->audioid === null ? null : (string) $audio->audioid,
                'order' => null,
                'title' => $this->filledString($audio->audiotitle),
                'language' => $this->filledString($audio->audiolanguage),
                'format' => $this->filledString($audio->audioformat),
                'codec' => null,
                'default' => null,
                'forced' => null,
                'channels_display' => $this->filledString($audio->audiochannels),
                'sample_rate_display' => $this->filledString($audio->audiosamplerate),
                'bitrate_display' => $this->filledString($audio->audiobitrate),
            ])->all(),
            'subtitle' => $subtitles->values()->map(fn (ReleaseSubtitle $subtitle, int $index): array => [
                'type' => 'subtitle',
                'index' => $index,
                'id' => $subtitle->subsid === null ? null : (string) $subtitle->subsid,
                'order' => null,
                'title' => null,
                'language' => $this->filledString($subtitle->subslanguage),
                'format' => null,
                'codec' => null,
                'default' => null,
                'forced' => null,
            ])->all(),
        ];

        return [
            'identity' => $this->identity($embeddedTitle, $musicTags !== null),
            'container' => [
                'source_filename' => $this->filledString($general?->file_name),
                'format' => $this->filledString($video?->containerformat)
                    ?? $this->filledString($audioTags?->audio_format),
                'duration_display' => $this->filledString($video?->videoduration),
                'overall_bitrate_display' => $this->filledString($video?->overallbitrate),
            ],
            'music_tags' => $musicTags,
            'streams' => $streams,
        ];
    }

    /** @return array<string, mixed>|null */
    private function legacyMusicTags(?ReleaseAudioTag $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        return $this->filledArray([
            'track_title' => $tags->track_name,
            'track_number' => $tags->track_position,
            'track_total' => $tags->track_position_total,
            'album' => $tags->album,
            'artist' => $tags->performer,
            'album_artist' => $tags->album_performer,
            'genre' => $tags->genre,
            'recorded_date' => $tags->recorded_date,
            'musicbrainz_release_id' => $tags->musicbrainz_album_id,
            'musicbrainz_recording_id' => $tags->musicbrainz_track_id,
        ]);
    }

    /**
     * @return array{label: string, title: string|null}
     */
    private function identity(?string $embeddedTitle, bool $isMusic): array
    {
        if ($isMusic) {
            return ['label' => 'Embedded track title', 'title' => $embeddedTitle];
        }

        return $embeddedTitle === null
            ? ['label' => 'Container', 'title' => null]
            : ['label' => 'Embedded movie title', 'title' => $embeddedTitle];
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return array{video: list<array<string, mixed>>, audio: list<array<string, mixed>>, subtitle: list<array<string, mixed>>}
     */
    private function groupStreams(array $streams): array
    {
        $grouped = ['video' => [], 'audio' => [], 'subtitle' => []];
        foreach ($streams as $stream) {
            $type = $stream['type'] ?? null;
            if (is_string($type) && array_key_exists($type, $grouped)) {
                $grouped[$type][] = $stream;
            }
        }

        return $grouped;
    }

    /** @return array<string, mixed>|null */
    private function filledArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $filled = array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== '');

        return $filled === [] ? null : $filled;
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
