<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AudioPreviewEncoding;
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
     * MediaInfo General-track format names mapped to the container extension the
     * encoder would write if it copied the stream instead of re-encoding it.
     *
     * Lossless formats the browser cannot play are listed too: they map to their
     * own extension, which never matches a preview container, so the comparison
     * in {@see self::previewEncoding()} reports them as transcoded. That is also
     * what disambiguates MP2 from MP3 -- MediaInfo calls both "MPEG Audio", and
     * only the preview extension says which one was copyable.
     *
     * @var array<string, string>
     */
    private const array SOURCE_FORMAT_EXTENSIONS = [
        'mpeg audio' => 'mp3',
        'mp3' => 'mp3',
        'mpeg-4' => 'm4a',
        'm4a' => 'm4a',
        'aac' => 'm4a',
        'adts' => 'm4a',
        'ogg' => 'ogg',
        'vorbis' => 'ogg',
        'opus' => 'opus',
        'flac' => 'flac',
        'wave' => 'wav',
        'wav' => 'wav',
        'wave64' => 'w64',
        'w64' => 'w64',
        'wavpack' => 'wv',
        'wv' => 'wv',
        "monkey's audio" => 'ape',
        'ape' => 'ape',
        'tta' => 'tta',
        'true audio' => 'tta',
        'aiff' => 'aiff',
        'windows media' => 'wma',
        'wma' => 'wma',
        'asf' => 'wma',
        'realmedia' => 'ra',
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
     * How the preview clip was produced, or null when the source format was not
     * recorded or is not one this pipeline knows, where the answer would be a
     * guess.
     *
     * The clip is a stream copy whenever its container matches the source
     * format; anything else was re-encoded, which the pipeline only ever does to
     * FLAC.
     */
    public function previewEncoding(): ?AudioPreviewEncoding
    {
        $extension = $this->previewExtension();
        $sourceFormat = strtolower(trim((string) $this->audio_format));

        if ($extension === null || $sourceFormat === '') {
            return null;
        }

        $sourceExtension = self::SOURCE_FORMAT_EXTENSIONS[$sourceFormat] ?? null;
        if ($sourceExtension === null) {
            return null;
        }

        return $sourceExtension === $extension
            ? AudioPreviewEncoding::StreamCopy
            : AudioPreviewEncoding::FlacTranscode;
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
