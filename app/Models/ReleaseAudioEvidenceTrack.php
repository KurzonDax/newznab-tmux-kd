<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReleaseAudioEvidenceTrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed audio artifact belonging to an immutable evidence revision.
 *
 * @property int $id
 * @property int $release_audio_evidence_id
 * @property string $source_kind
 * @property int $source_ordinal
 * @property string|null $source_path
 * @property string $raw_filename
 * @property int|null $segment_count
 * @property int|null $disc_number
 * @property int|null $track_number
 * @property string|null $normalized_title_hint
 * @property string|null $normalized_artist_hint
 * @property array<string, mixed>|null $raw_tags
 * @property float|null $whole_duration_seconds
 * @property float|null $decoded_duration_seconds
 * @property bool|null $source_file_complete
 * @property bool|null $source_starts_at_zero
 * @property bool|null $whole_duration_reliable
 * @property-read ReleaseAudioEvidence|null $evidence
 *
 * @mixin \Eloquent
 */
class ReleaseAudioEvidenceTrack extends Model
{
    /** @use HasFactory<ReleaseAudioEvidenceTrackFactory> */
    use HasFactory;

    /** @var array<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_audio_evidence_id' => 'integer',
            'source_ordinal' => 'integer',
            'segment_count' => 'integer',
            'disc_number' => 'integer',
            'track_number' => 'integer',
            'raw_tags' => 'array',
            'whole_duration_seconds' => 'float',
            'decoded_duration_seconds' => 'float',
            'source_file_complete' => 'boolean',
            'source_starts_at_zero' => 'boolean',
            'whole_duration_reliable' => 'boolean',
        ];
    }

    /** @return BelongsTo<ReleaseAudioEvidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ReleaseAudioEvidence::class, 'release_audio_evidence_id');
    }
}
