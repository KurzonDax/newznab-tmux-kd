<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReleaseAudioEvidenceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable collection-level evidence captured by the audio processing path.
 *
 * @property int $id
 * @property int $releases_id
 * @property int $revision
 * @property string $evidence_hash
 * @property int $schema_version
 * @property string $provenance
 * @property array<string, mixed> $release_snapshot
 * @property bool|null $archive_manifest_complete
 * @property bool|null $source_file_complete
 * @property bool|null $source_starts_at_zero
 * @property bool|null $whole_duration_reliable
 * @property bool|null $only_one_track_probed
 * @property list<array<string, mixed>> $nzb_manifest
 * @property list<array<string, mixed>> $archive_manifest
 * @property list<array<string, mixed>> $sidecar_manifest
 * @property Carbon $captured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release|null $release
 * @property-read Collection<int, ReleaseAudioEvidenceTrack> $tracks
 * @property-read Collection<int, ReleaseMusicIdentification> $musicIdentifications
 *
 * @mixin \Eloquent
 */
class ReleaseAudioEvidence extends Model
{
    /** @use HasFactory<ReleaseAudioEvidenceFactory> */
    use HasFactory;

    protected $table = 'release_audio_evidence';

    /** @var array<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'releases_id' => 'integer',
            'revision' => 'integer',
            'schema_version' => 'integer',
            'release_snapshot' => 'array',
            'archive_manifest_complete' => 'boolean',
            'source_file_complete' => 'boolean',
            'source_starts_at_zero' => 'boolean',
            'whole_duration_reliable' => 'boolean',
            'only_one_track_probed' => 'boolean',
            'nzb_manifest' => 'array',
            'archive_manifest' => 'array',
            'sidecar_manifest' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /** @return HasMany<ReleaseAudioEvidenceTrack, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(ReleaseAudioEvidenceTrack::class, 'release_audio_evidence_id');
    }

    /** @return HasMany<ReleaseMusicIdentification, $this> */
    public function musicIdentifications(): HasMany
    {
        return $this->hasMany(ReleaseMusicIdentification::class, 'release_audio_evidence_id');
    }
}
