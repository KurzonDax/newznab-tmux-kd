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
     * Forget the preview recorded on these releases' rows, keeping the tags.
     *
     * The inverse of what the audio processor writes when a clip is encoded;
     * used when a release is sent back through the audio path.
     *
     * @param  list<int>  $releaseIds
     */
    public static function clearPreviews(array $releaseIds): int
    {
        if ($releaseIds === []) {
            return 0;
        }

        return self::query()->whereIn('releases_id', $releaseIds)->update([
            'has_preview' => 0,
            'preview_extension' => null,
            'preview_mime' => null,
            'preview_seconds' => null,
            'preview_bytes' => null,
            'has_spectrogram' => 0,
        ]);
    }

    /**
     * Preview containers the audio pipeline is allowed to write, mapped to the
     * MIME type used when the one recorded on the row is unusable.
     *
     * This is the allow-list the served path is validated against: the
     * extension reaches a filesystem path, so anything outside it is a 404
     * rather than a lookup.
     *
     * @var array<string, string>
     */
    public const array PREVIEW_MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
    ];

    /**
     * The clip's container, or null when the row names one this application does
     * not serve. Callers treat null as "there is no playable preview".
     */
    public function previewExtension(): ?string
    {
        $extension = strtolower((string) $this->preview_extension);

        return array_key_exists($extension, self::PREVIEW_MIME_TYPES) ? $extension : null;
    }

    /**
     * The MIME type to serve the clip as: the one recorded when it was encoded,
     * unless that is not a well-formed media type, in which case the container's
     * canonical type. Null whenever {@see self::previewExtension()} is.
     */
    public function previewMimeType(): ?string
    {
        $extension = $this->previewExtension();
        if ($extension === null) {
            return null;
        }

        $stored = (string) $this->preview_mime;

        if (preg_match('#\A[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*\z#iD', $stored) === 1) {
            return $stored;
        }

        return self::PREVIEW_MIME_TYPES[$extension];
    }

    /**
     * The MIME type only when this row records a preview the application can serve.
     */
    public function playablePreviewMimeType(): ?string
    {
        return $this->has_preview ? $this->previewMimeType() : null;
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
