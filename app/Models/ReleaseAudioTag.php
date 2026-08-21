<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tag metadata read off the audio file sampled during post-processing.
 *
 * One row per release: the pipeline only ever inspects a single sampled file,
 * so per-track rows would be misleading. `raw_tags` keeps the whole General
 * track attribute bag for anything without a column of its own.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property string|null $album
 * @property string|null $album_performer
 * @property string|null $performer
 * @property string|null $track_name
 * @property int|null $track_position
 * @property int|null $track_position_total
 * @property string|null $genre
 * @property string|null $recorded_date
 * @property int|null $recorded_year
 * @property string|null $musicbrainz_album_id
 * @property string|null $musicbrainz_artist_id
 * @property string|null $musicbrainz_track_id
 * @property string|null $musicbrainz_release_group_id
 * @property string|null $source_file
 * @property string|null $audio_format
 * @property array<string, mixed>|null $raw_tags
 * @property bool $has_preview
 * @property string|null $preview_extension
 * @property string|null $preview_mime
 * @property int|null $preview_seconds
 * @property int|null $preview_bytes
 * @property bool $has_spectrogram
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release|null $release
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseAudioTag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseAudioTag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseAudioTag query()
 */
class ReleaseAudioTag extends Model
{
    /**
     * @var string
     */
    protected $table = 'release_audio_tags';

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'releases_id' => 'integer',
            'track_position' => 'integer',
            'track_position_total' => 'integer',
            'recorded_year' => 'integer',
            'raw_tags' => 'array',
            'has_preview' => 'boolean',
            'preview_seconds' => 'integer',
            'preview_bytes' => 'integer',
            'has_spectrogram' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
