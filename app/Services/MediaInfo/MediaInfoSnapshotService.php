<?php

declare(strict_types=1);

namespace App\Services\MediaInfo;

use App\Models\MediaInfoProbe;
use App\Models\MediaInfoTrack;
use App\Services\MediaInfo\Contracts\MediaInfoSnapshotWriter;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\DTO\MediaInfoSnapshotData;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mhor\MediaInfo\Attribute\Duration;
use Mhor\MediaInfo\Attribute\FloatRate;
use Mhor\MediaInfo\Attribute\Mode;
use Mhor\MediaInfo\Attribute\Rate;
use Mhor\MediaInfo\Attribute\Ratio;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Mhor\MediaInfo\Type\AbstractType;
use Mhor\MediaInfo\Type\General;

final class MediaInfoSnapshotService implements MediaInfoSnapshotWriter
{
    public const SCHEMA_VERSION = 1;

    public const MAX_CURATED_STRING_LENGTH = 2048;

    public function __construct(
        private readonly MediaInfoDiagnosticNormalizer $diagnostics = new MediaInfoDiagnosticNormalizer,
    ) {}

    public function capture(
        int $releaseId,
        MediaInfoContainer $container,
        MediaInfoProbeContext $context,
    ): MediaInfoProbe {
        return DB::transaction(function () use ($releaseId, $container, $context): MediaInfoProbe {
            $general = $container->getGeneral();
            $diagnostic = $this->diagnostics->normalize($general?->get() ?? []);

            $probe = MediaInfoProbe::query()->create([
                'releases_id' => $releaseId,
                'captured_at' => $context->capturedAt ?? Carbon::now('UTC'),
                'source_kind' => $context->sourceKind->value,
                'source_filename' => $context->sourceFilename === null
                    ? null
                    : $this->boundString(basename($context->sourceFilename)),
                'source_completeness' => $context->sourceCompleteness->value,
                'schema_version' => self::SCHEMA_VERSION,
                'embedded_title' => $general === null
                    ? null
                    : $this->stringValue($general, ['movie_name', 'title', 'track_name']),
                'container_format' => $this->modeValue($general?->get('format')),
                'duration_ms' => $this->integerValue($general?->get('duration')),
                'overall_bitrate_bps' => $this->integerValue($general?->get('overall_bit_rate')),
                'music_tags' => $this->musicTags($general),
                'diagnostic_raw' => $diagnostic['raw'],
                'diagnostic_filtered' => $diagnostic['filtered'],
                'diagnostic_truncated' => $diagnostic['truncated'],
            ]);

            foreach ($this->trackGroups($container) as $type => $tracks) {
                foreach ($tracks as $index => $track) {
                    $probe->tracks()->create($this->trackAttributes($type, $index, $track));
                }
            }

            $retainedIds = MediaInfoProbe::query()
                ->where('releases_id', $releaseId)
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(2)
                ->pluck('id');

            MediaInfoProbe::query()
                ->where('releases_id', $releaseId)
                ->whereNotIn('id', $retainedIds)
                ->delete();

            return $probe->load('tracks');
        }, 3);
    }

    public function selectedForRelease(int $releaseId): ?MediaInfoSnapshotData
    {
        $probes = MediaInfoProbe::query()
            ->where('releases_id', $releaseId)
            ->with(['tracks' => fn ($query) => $query->orderBy('id')])
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        /** @var MediaInfoProbe|null $probe */
        $probe = $probes->firstWhere('source_completeness', MediaInfoSourceCompleteness::Complete->value)
            ?? $probes->first();
        if ($probe === null) {
            return null;
        }

        return new MediaInfoSnapshotData(
            container: [
                'embedded_title' => $probe->embedded_title,
                'source_filename' => $probe->source_filename,
                'format' => $probe->container_format,
                'duration_ms' => $probe->duration_ms === null ? null : (int) $probe->duration_ms,
                'overall_bitrate_bps' => $probe->overall_bitrate_bps === null ? null : (int) $probe->overall_bitrate_bps,
                'music_tags' => $probe->music_tags,
            ],
            streams: $probe->tracks->map(fn (MediaInfoTrack $track): array => [
                'type' => $track->type,
                'index' => (int) $track->track_index,
                'id' => $track->source_id,
                'order' => $track->stream_order,
                'title' => $track->title,
                'language' => $track->language,
                'format' => $track->format,
                'codec' => $track->codec,
                'default' => $track->is_default,
                'forced' => $track->is_forced,
                'duration_ms' => $this->nullableInt($track->duration_ms),
                'bitrate_bps' => $this->nullableInt($track->bitrate_bps),
                'width' => $this->nullableInt($track->width),
                'height' => $this->nullableInt($track->height),
                'aspect_ratio' => $track->aspect_ratio,
                'frame_rate' => $track->frame_rate,
                'profile' => $track->profile,
                'bit_depth' => $this->nullableInt($track->bit_depth),
                'hdr_format' => $track->hdr_format,
                'color_primaries' => $track->color_primaries,
                'transfer_characteristics' => $track->transfer_characteristics,
                'matrix_coefficients' => $track->matrix_coefficients,
                'channels' => $this->nullableInt($track->channels),
                'channel_layout' => $track->channel_layout,
                'sample_rate_hz' => $this->nullableInt($track->sample_rate_hz),
            ])->all(),
            provenance: [
                'captured_at' => Carbon::parse((string) $probe->captured_at)->toIso8601String(),
                'source_kind' => $probe->source_kind,
                'source_completeness' => $probe->source_completeness,
                'schema_version' => (int) $probe->schema_version,
            ],
        );
    }

    /** @return array<string, list<AbstractType>> */
    private function trackGroups(MediaInfoContainer $container): array
    {
        return [
            'video' => $container->getVideos(),
            'audio' => $container->getAudios(),
            'subtitle' => $container->getSubtitles(),
        ];
    }

    /** @return array<string, mixed> */
    private function trackAttributes(string $type, int $index, AbstractType $track): array
    {
        $diagnostic = $this->diagnostics->normalize($track->get());

        return [
            'type' => $type,
            'track_index' => $index,
            'source_id' => $this->modeValue($track->get('id')),
            'stream_order' => $this->scalarString($track->get('streamorder')),
            'title' => $this->scalarString($track->get('title')),
            'language' => $this->languageValue($track->get('language')),
            'format' => $this->modeValue($track->get('format')),
            'codec' => $this->modeValue($track->get('codec_id')),
            'is_default' => $this->booleanValue($track->get('default')),
            'is_forced' => $this->booleanValue($track->get('forced')),
            'duration_ms' => $this->integerValue($track->get('duration')),
            'bitrate_bps' => $this->integerValue($track->get('bit_rate')),
            'width' => $this->integerValue($track->get('width')),
            'height' => $this->integerValue($track->get('height')),
            'aspect_ratio' => $this->ratioValue($track->get('display_aspect_ratio')),
            'frame_rate' => $this->floatValue($track->get('frame_rate')),
            'profile' => $this->scalarString($track->get('format_profile')),
            'bit_depth' => $this->integerValue($track->get('bit_depth')),
            'hdr_format' => $this->scalarString($track->get('hdr_format')),
            'color_primaries' => $this->scalarString($track->get('colour_primaries')),
            'transfer_characteristics' => $this->scalarString($track->get('transfer_characteristics')),
            'matrix_coefficients' => $this->scalarString($track->get('matrix_coefficients')),
            'channels' => $this->integerValue($track->get('channel_s')),
            'channel_layout' => $this->modeValue($track->get('channel_positions'))
                ?? $this->scalarString($track->get('channel_layout')),
            'sample_rate_hz' => $this->integerValue($track->get('sampling_rate')),
            'diagnostic_raw' => $diagnostic['raw'],
            'diagnostic_filtered' => $diagnostic['filtered'],
            'diagnostic_truncated' => $diagnostic['truncated'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function musicTags(?General $general): ?array
    {
        if ($general === null) {
            return null;
        }

        $tags = [
            'track_title' => $this->stringValue($general, ['track_name', 'title']),
            'track_number' => $this->integerValue($general->get('track_name_position')),
            'track_total' => $this->integerValue($general->get('track_name_total')),
            'disc_number' => $this->integerValue($general->get('part_position')),
            'disc_total' => $this->integerValue($general->get('part_position_total')),
            'album' => $this->scalarString($general->get('album')),
            'artist' => $this->scalarString($general->get('performer')),
            'album_artist' => $this->scalarString($general->get('album_performer')),
            'genre' => $this->scalarString($general->get('genre')),
            'recorded_date' => $this->scalarString($general->get('recorded_date')),
            'musicbrainz_release_id' => $this->stringValue($general, ['musicbrainz_releaseid', 'musicbrainz_release_id']),
            'musicbrainz_recording_id' => $this->stringValue($general, ['musicbrainz_recordingid', 'musicbrainz_recording_id']),
        ];

        $tags = array_filter($tags, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $tags === [] ? null : $tags;
    }

    /** @param list<string> $keys */
    private function stringValue(AbstractType $type, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->scalarString($type->get($key));
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function integerValue(mixed $value): ?int
    {
        return match (true) {
            $value instanceof Duration => $value->getMilliseconds(),
            $value instanceof Rate => $value->getAbsoluteValue(),
            $value instanceof Mode && is_numeric($value->getShortName()) => (int) $value->getShortName(),
            is_numeric($value) => (int) $value,
            default => null,
        };
    }

    private function floatValue(mixed $value): ?float
    {
        return match (true) {
            $value instanceof FloatRate => $value->getFloatAbsoluteValue(),
            is_numeric($value) => (float) $value,
            default => null,
        };
    }

    private function ratioValue(mixed $value): ?string
    {
        return $value instanceof Ratio ? $this->boundString($value->getTextValue()) : $this->scalarString($value);
    }

    private function modeValue(mixed $value): ?string
    {
        return $value instanceof Mode ? $this->boundString($value->getFullName()) : $this->scalarString($value);
    }

    private function languageValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        return $this->scalarString($value);
    }

    private function booleanValue(mixed $value): ?bool
    {
        $normalized = strtolower((string) ($this->modeValue($value) ?? ''));

        return match ($normalized) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default => null,
        };
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = end($value);
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return $this->boundString((string) $value);
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function boundString(string $value): string
    {
        return mb_substr($value, 0, self::MAX_CURATED_STRING_LENGTH);
    }
}
