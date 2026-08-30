<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReleaseAudioEvidence;
use App\Models\ReleaseAudioEvidenceTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseAudioEvidenceTrack>
 */
class ReleaseAudioEvidenceTrackFactory extends Factory
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
            'source_kind' => 'nzb',
            'source_ordinal' => 1,
            'raw_filename' => $this->faker->unique()->word().'.flac',
        ];
    }
}
