<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseMusicIdentification;
use App\Services\MusicIdentity\Enums\AcceptedIdentityScope;
use App\Services\MusicIdentity\Enums\IdentificationBand;
use App\Services\MusicIdentity\Enums\IdentificationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseMusicIdentification>
 */
class ReleaseMusicIdentificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'release_audio_evidence_id' => ReleaseAudioEvidence::factory(),
            'releases_id' => static fn (array $attributes): int => ReleaseAudioEvidence::query()
                ->findOrFail((int) $attributes['release_audio_evidence_id'])
                ->releases_id,
            'evidence_hash' => static fn (array $attributes): string => ReleaseAudioEvidence::query()
                ->findOrFail((int) $attributes['release_audio_evidence_id'])
                ->evidence_hash,
            'state' => IdentificationStatus::AcceptedReleaseGroup,
            'score' => 96,
            'band' => IdentificationBand::Strong,
            'accepted_scope' => AcceptedIdentityScope::ReleaseGroup,
            'musicbrainz_recording_id' => null,
            'musicbrainz_release_id' => null,
            'musicbrainz_release_group_id' => $this->faker->uuid(),
            'reasons' => [['code' => 'fixture', 'description' => 'Factory decision.', 'contribution' => 96]],
            'feature_contributions' => ['fixture' => 96],
            'runner_up_margin' => 8,
            'attempt_count' => 1,
            'lease_token' => null,
            'lease_expires_at' => null,
            'next_attempt_at' => null,
            'last_operational_error' => null,
            'algorithm_version' => 'music-identity-v1',
            'resolver_version' => 'resolver-v1',
            'normalizer_version' => 'normalizer-v1',
            'scorer_version' => 'whole-release-v1',
            'policy_version' => 'shadow-v1',
            'mirror_replicated_at' => null,
            'mirror_searched_at' => null,
            'acoustid_looked_up_at' => null,
            'supersedes_id' => null,
            'decided_at' => now(),
        ];
    }
}
