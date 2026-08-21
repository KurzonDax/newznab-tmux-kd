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
     * MediaInfo General-track format names mapped to the container extension the
     * encoder would write if it copied the stream instead of re-encoding it.
     *
     * Lossless formats the browser cannot play are listed too: they map to their
     * own extension, which never matches a preview container, so the comparison
     * in {@see self::previewEncodingLabel()} reports them as transcoded. That is
     * also what disambiguates MP2 from MP3 -- MediaInfo calls both "MPEG Audio",
     * and only the preview extension says which one was copyable.
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
     * How the preview clip was produced, or null when the source format was not
     * recorded or is not one this pipeline knows, where the answer would be a
     * guess.
     *
     * The clip is a stream copy whenever its container matches the source
     * format; anything else was re-encoded, which the pipeline only ever does to
     * FLAC.
     */
    public function previewEncodingLabel(): ?string
    {
        $extension = strtolower((string) $this->preview_extension);
        $sourceFormat = strtolower(trim((string) $this->audio_format));

        if ($extension === '' || $sourceFormat === '') {
            return null;
        }

        $sourceExtension = self::SOURCE_FORMAT_EXTENSIONS[$sourceFormat] ?? null;
        if ($sourceExtension === null) {
            return null;
        }

        return $sourceExtension === $extension ? 'stream copy' : 'FLAC transcode';
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
