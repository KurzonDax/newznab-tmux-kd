<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Database\Factories\ReleaseMusicIdentificationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $releases_id
 * @property int $release_audio_evidence_id
 * @property string $evidence_hash
 * @property IdentificationStatus $state
 * @property int $score
 * @property IdentificationBand $band
 * @property AcceptedIdentityScope|null $accepted_scope
 * @property string|null $musicbrainz_recording_id
 * @property string|null $musicbrainz_release_id
 * @property string|null $musicbrainz_release_group_id
 * @property list<array{code: string, description: string, contribution: int}> $reasons
 * @property array<string, int> $feature_contributions
 * @property int|null $runner_up_margin
 * @property int $attempt_count
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_operational_error
 * @property string $algorithm_version
 * @property string $resolver_version
 * @property string $normalizer_version
 * @property string $scorer_version
 * @property string $policy_version
 * @property Carbon|null $mirror_replicated_at
 * @property Carbon|null $mirror_searched_at
 * @property Carbon|null $acoustid_looked_up_at
 * @property int|null $supersedes_id
 * @property Carbon|null $decided_at
 * @property-read Release|null $release
 * @property-read ReleaseAudioEvidence|null $evidence
 * @property-read ReleaseMusicIdentification|null $supersedes
 * @property-read Collection<int, ReleaseMusicIdentification> $supersededBy
 * @property-read Collection<int, ReleaseMusicCandidateAttempt> $candidateAttempts
 *
 * @mixin \Eloquent
 */
class ReleaseMusicIdentification extends Model
{
    /** @use HasFactory<ReleaseMusicIdentificationFactory> */
    use HasFactory;

    /** @var array<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'releases_id' => 'integer',
            'release_audio_evidence_id' => 'integer',
            'state' => IdentificationStatus::class,
            'score' => 'integer',
            'band' => IdentificationBand::class,
            'accepted_scope' => AcceptedIdentityScope::class,
            'reasons' => 'array',
            'feature_contributions' => 'array',
            'runner_up_margin' => 'integer',
            'attempt_count' => 'integer',
            'lease_expires_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'mirror_replicated_at' => 'datetime',
            'mirror_searched_at' => 'datetime',
            'acoustid_looked_up_at' => 'datetime',
            'supersedes_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /** @return BelongsTo<ReleaseAudioEvidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ReleaseAudioEvidence::class, 'release_audio_evidence_id');
    }

    /** @return BelongsTo<ReleaseMusicIdentification, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasMany<ReleaseMusicIdentification, $this> */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    /** @return HasMany<ReleaseMusicCandidateAttempt, $this> */
    public function candidateAttempts(): HasMany
    {
        return $this->hasMany(ReleaseMusicCandidateAttempt::class, 'release_music_identification_id')->orderBy('rank');
    }
}
