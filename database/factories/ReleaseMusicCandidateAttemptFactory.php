<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReleaseMusicCandidateAttempt;
use App\Models\ReleaseMusicIdentification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseMusicCandidateAttempt>
 */
class ReleaseMusicCandidateAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'release_music_identification_id' => ReleaseMusicIdentification::factory(),
            'rank' => 1,
            'score' => 96,
            'musicbrainz_recording_id' => null,
            'musicbrainz_release_id' => null,
            'musicbrainz_release_group_id' => $this->faker->uuid(),
            'display_snapshot' => ['title' => $this->faker->words(3, true)],
            'feature_vector' => ['observed_coverage' => 1.0],
            'score_contributions' => ['fixture' => 96],
            'contradictions' => [],
            'provenance' => ['factory'],
            'response_cache_keys' => [],
        ];
    }
}
