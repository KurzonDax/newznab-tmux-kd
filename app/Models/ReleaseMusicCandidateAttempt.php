<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReleaseMusicCandidateAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $release_music_identification_id
 * @property int $rank
 * @property int $score
 * @property string|null $musicbrainz_recording_id
 * @property string|null $musicbrainz_release_id
 * @property string|null $musicbrainz_release_group_id
 * @property array<string, scalar|null> $display_snapshot
 * @property array<string, int|float|string|bool|null> $feature_vector
 * @property array<string, int> $score_contributions
 * @property list<string> $contradictions
 * @property list<string> $provenance
 * @property list<string> $response_cache_keys
 * @property-read ReleaseMusicIdentification|null $identification
 *
 * @mixin \Eloquent
 */
class ReleaseMusicCandidateAttempt extends Model
{
    /** @use HasFactory<ReleaseMusicCandidateAttemptFactory> */
    use HasFactory;

    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'release_music_identification_id' => 'integer',
            'rank' => 'integer',
            'score' => 'integer',
            'display_snapshot' => 'array',
            'feature_vector' => 'array',
            'score_contributions' => 'array',
            'contradictions' => 'array',
            'provenance' => 'array',
            'response_cache_keys' => 'array',
        ];
    }

    /** @return BelongsTo<ReleaseMusicIdentification, $this> */
    public function identification(): BelongsTo
    {
        return $this->belongsTo(ReleaseMusicIdentification::class, 'release_music_identification_id');
    }
}
