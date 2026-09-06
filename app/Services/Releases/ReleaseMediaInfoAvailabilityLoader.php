<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReleaseMediaInfoAvailabilityLoader
{
    /** @param iterable<int, object> $releases */
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

        $queries = [];
        foreach (['media_info_probes', 'media_infos', 'video_data', 'audio_data', 'release_subtitles'] as $table) {
            if (Schema::hasTable($table)) {
                $queries[] = $this->sourceQuery($table, array_keys($rowsByReleaseId));
            }
        }
        $needsAudioTagQuery = collect($rowsByReleaseId)->contains(
            static fn (object $release): bool => $release instanceof Model
                ? ! array_key_exists('has_media_info', $release->getAttributes())
                : ! property_exists($release, 'has_media_info'),
        );
        if ($needsAudioTagQuery && Schema::hasTable('release_audio_tags')) {
            $queries[] = $this->sourceQuery('release_audio_tags', array_keys($rowsByReleaseId));
        }

        $available = [];
        if ($queries !== []) {
            $query = array_shift($queries);
            foreach ($queries as $union) {
                $query->union($union);
            }
            $available = array_flip($query->pluck('releases_id')->map(static fn (mixed $id): int => (int) $id)->all());
        }

        foreach ($rowsByReleaseId as $releaseId => $release) {
            $existing = $release instanceof Model
                ? (bool) ($release->getAttributes()['has_media_info'] ?? false)
                : (bool) ($release->has_media_info ?? false);
            $value = $existing || isset($available[$releaseId]);
            if ($release instanceof Model) {
                $release->setAttribute('has_media_info', $value);
            } else {
                $release->has_media_info = $value;
            }
        }
    }

    /** @param list<int> $releaseIds */
    private function sourceQuery(string $table, array $releaseIds): Builder
    {
        $query = DB::table($table)->select('releases_id')->whereIn('releases_id', $releaseIds);

        return match ($table) {
            'media_info_probes' => $query->where(function (Builder $values): void {
                $this->whereAnyFilled($values, [
                    'embedded_title',
                    'source_filename',
                    'container_format',
                    'music_tags',
                ], ['duration_ms', 'overall_bitrate_bps']);
                $values->orWhereExists(static fn (Builder $tracks): Builder => $tracks
                    ->selectRaw('1')
                    ->from('media_info_tracks')
                    ->whereColumn('media_info_tracks.media_info_probe_id', 'media_info_probes.id'));
            }),
            'media_infos' => $query->where(fn (Builder $values): Builder => $this->whereAnyFilled(
                $values,
                ['movie_name', 'file_name'],
            )),
            'video_data' => $query->where(fn (Builder $values): Builder => $this->whereAnyFilled(
                $values,
                [
                    'containerformat',
                    'overallbitrate',
                    'videoduration',
                    'videoformat',
                    'videocodec',
                    'videoaspect',
                    'videolibrary',
                ],
                ['videowidth', 'videoheight', 'videoframerate'],
            )),
            'audio_data' => $query->where(fn (Builder $values): Builder => $this->whereAnyFilled(
                $values,
                [
                    'audioformat',
                    'audiobitrate',
                    'audiochannels',
                    'audiosamplerate',
                    'audiolanguage',
                    'audiotitle',
                ],
                ['audioid'],
            )),
            'release_subtitles' => $query->where(fn (Builder $values): Builder => $this->whereAnyFilled(
                $values,
                ['subslanguage'],
                ['subsid'],
            )),
            'release_audio_tags' => $query->where(fn (Builder $values): Builder => $this->whereAnyFilled(
                $values,
                [
                    'album',
                    'performer',
                    'album_performer',
                    'genre',
                    'recorded_date',
                    'track_name',
                    'musicbrainz_album_id',
                    'musicbrainz_track_id',
                    'audio_format',
                ],
                ['track_position', 'track_position_total'],
            )),
            default => $query,
        };
    }

    /**
     * @param  list<string>  $stringColumns
     * @param  list<string>  $nullableColumns
     */
    private function whereAnyFilled(Builder $query, array $stringColumns, array $nullableColumns = []): Builder
    {
        foreach ($stringColumns as $column) {
            $query->orWhere(static fn (Builder $value): Builder => $value
                ->whereNotNull($column)
                ->where($column, '!=', ''));
        }
        foreach ($nullableColumns as $column) {
            $query->orWhereNotNull($column);
        }

        return $query;
    }
}
