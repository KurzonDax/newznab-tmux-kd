<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metadata for the Clip stored as a release's video artifact (see CONTEXT.md).
 *
 * One row per release: a release has exactly one video artifact slot. A row
 * exists for stream-copy and fallback-transcoded Clips — releases whose
 * artifact is the legacy downscaled OGV transcode are identified by
 * `releases.videostatus` alone.
 * Kept in its own table because `releases` is deliberately slim; follows the
 * {@see ReleaseAudioTag} precedent.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property string $extension
 * @property string $mime
 * @property int|null $duration_seconds
 * @property int|null $bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release|null $release
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseVideoClip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseVideoClip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseVideoClip query()
 */
class ReleaseVideoClip extends Model
{
    /**
     * @var string
     */
    protected $table = 'release_video_clips';

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Video containers the application serves, mapped to the MIME type used
     * when the one recorded on the row is unusable.
     *
     * This is the allow-list the served path is validated against: the
     * extension reaches a filesystem path, so anything outside it is a 404
     * rather than a lookup. `ogv` is the legacy downscaled transcode's
     * container — the pipeline never writes a Clip row for it, but the one
     * serving route covers both artifacts.
     *
     * @var array<string, string>
     */
    public const array VIDEO_MIME_TYPES = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'releases_id' => 'integer',
            'duration_seconds' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /**
     * The Clip's container, or null when the row names one this application
     * does not serve. Callers treat null as "there is no playable Clip".
     */
    public function clipExtension(): ?string
    {
        $extension = strtolower((string) $this->extension);

        return array_key_exists($extension, self::VIDEO_MIME_TYPES) ? $extension : null;
    }

    /**
     * The MIME type to serve the Clip as: the one recorded when it was
     * remuxed, unless that is not a well-formed media type, in which case the
     * container's canonical type. Null whenever {@see self::clipExtension()} is.
     */
    public function clipMimeType(): ?string
    {
        $extension = $this->clipExtension();
        if ($extension === null) {
            return null;
        }

        $stored = (string) $this->mime;

        if (preg_match('#\A[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*\z#iD', $stored) === 1) {
            return $stored;
        }

        return self::VIDEO_MIME_TYPES[$extension];
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }
}
