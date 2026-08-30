<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Release;
use App\Models\ReleaseAudioEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseAudioEvidence>
 */
class ReleaseAudioEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'releases_id' => Release::factory(),
            'revision' => 1,
            'evidence_hash' => $this->faker->unique()->sha256(),
            'schema_version' => 1,
            'provenance' => 'captured',
            'release_snapshot' => [],
            'archive_manifest_complete' => null,
            'source_file_complete' => null,
            'source_starts_at_zero' => null,
            'whole_duration_reliable' => null,
            'only_one_track_probed' => null,
            'nzb_manifest' => [],
            'archive_manifest' => [],
            'sidecar_manifest' => [],
            'captured_at' => now(),
        ];
    }
}
