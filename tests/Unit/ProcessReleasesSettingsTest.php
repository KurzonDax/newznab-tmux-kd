<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Data\ProcessReleasesSettings;
use Tests\TestCase;

/**
 * The release-creation loop keeps iterating while an iteration fills its batch, so a
 * non-positive limit made `0 >= 0` true forever against a drained queue. The limit is
 * therefore an invariant of the settings object rather than a check at each consumer.
 */
class ProcessReleasesSettingsTest extends TestCase
{
    public function test_a_stored_zero_release_creation_limit_resolves_to_the_coded_default(): void
    {
        $settings = ProcessReleasesSettings::forDatabase(['maxnzbsprocessed' => '0']);

        $this->assertSame(1000, $settings->releaseCreationLimit);
    }

    public function test_a_stored_negative_release_creation_limit_resolves_to_the_coded_default(): void
    {
        $settings = ProcessReleasesSettings::forDatabase(['maxnzbsprocessed' => '-25']);

        $this->assertSame(1000, $settings->releaseCreationLimit);
    }

    public function test_direct_construction_below_one_resolves_to_the_coded_default(): void
    {
        $this->assertSame(1000, (new ProcessReleasesSettings(releaseCreationLimit: 0))->releaseCreationLimit);
        $this->assertSame(1000, (new ProcessReleasesSettings(releaseCreationLimit: -1))->releaseCreationLimit);
    }

    public function test_a_missing_release_creation_limit_resolves_to_the_coded_default(): void
    {
        $settings = ProcessReleasesSettings::forDatabase([]);

        $this->assertSame(1000, $settings->releaseCreationLimit);
    }

    public function test_a_blank_release_creation_limit_resolves_to_the_coded_default(): void
    {
        $settings = ProcessReleasesSettings::forDatabase(['maxnzbsprocessed' => '']);

        $this->assertSame(1000, $settings->releaseCreationLimit);
    }

    public function test_a_stored_positive_release_creation_limit_passes_through(): void
    {
        $settings = ProcessReleasesSettings::forDatabase(['maxnzbsprocessed' => '250']);

        $this->assertSame(250, $settings->releaseCreationLimit);
    }

    public function test_the_completion_upper_bound_still_clamps(): void
    {
        $this->assertSame(100, (new ProcessReleasesSettings(completion: 150))->completion);
        $this->assertSame(55, ProcessReleasesSettings::forDatabase(['completionpercent' => '55'])->completion);
    }
}
