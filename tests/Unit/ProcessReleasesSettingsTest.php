<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Data\ProcessReleasesSettings;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The incomplete-release wait is a retention window: anything that is not a positive number
     * of hours resolves to the seeded 72, so 0 never means "delete at once".
     *
     * @param  array<string, mixed>  $stored
     */
    #[DataProvider('incompleteReleaseWaits')]
    public function test_the_incomplete_release_wait_resolves_unusable_values_to_72_hours(array $stored, int $expected): void
    {
        $this->assertSame($expected, ProcessReleasesSettings::forDatabase($stored)->incompleteReleaseGraceHours);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function incompleteReleaseWaits(): array
    {
        return [
            'missing' => [[], 72],
            'blank' => [['incomplete_release_grace_hours' => ''], 72],
            'zero' => [['incomplete_release_grace_hours' => '0'], 72],
            'negative' => [['incomplete_release_grace_hours' => '-5'], 72],
            'non-numeric' => [['incomplete_release_grace_hours' => 'abc'], 72],
            'positive' => [['incomplete_release_grace_hours' => '24'], 24],
        ];
    }

    public function test_direct_construction_with_a_zero_incomplete_release_wait_resolves_to_72_hours(): void
    {
        $this->assertSame(72, (new ProcessReleasesSettings(incompleteReleaseGraceHours: 0))->incompleteReleaseGraceHours);
    }
}
