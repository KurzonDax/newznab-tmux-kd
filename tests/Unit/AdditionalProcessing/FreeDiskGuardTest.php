<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\FreeDiskGuard;
use Tests\TestCase;

class FreeDiskGuardTest extends TestCase
{
    public function test_it_allows_artifacts_at_or_above_ten_percent_free(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertTrue($guard->allows('/covers/video'));
    }

    public function test_it_refuses_artifacts_below_ten_percent_free(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float => 99.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows('/covers/video'));
    }

    public function test_unreadable_disk_metrics_refuse_the_artifact(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float|false => false,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows('/covers/video'));

        $guard = new FreeDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float|false => false,
        );

        $this->assertFalse($guard->allows('/covers/video'));
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['nntmux_settings.covers_minimum_free_fraction' => 0.25]);

        $guard = new FreeDiskGuard(
            static fn (string $path): float => 200.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows('/covers/video'));

        config(['nntmux_settings.covers_minimum_free_fraction' => 0.15]);

        $this->assertTrue($guard->allows('/covers/video'));
    }

    public function test_each_producer_is_measured_at_its_own_destination(): void
    {
        $video = $this->makeTempDirectory('covers-video');
        $sample = $this->makeTempDirectory('covers-sample');
        $measured = [];
        $guard = new FreeDiskGuard(
            static function (string $path) use (&$measured): float {
                $measured[] = $path;

                return 900.0;
            },
            static fn (string $path): float => 1000.0,
        );

        $guard->allows($video);
        $guard->allows($sample);

        $this->assertSame([$video, $sample], $measured);
    }

    public function test_a_destination_that_does_not_exist_yet_is_measured_at_its_nearest_parent(): void
    {
        $parent = $this->makeTempDirectory('covers-root');
        $measured = [];
        $guard = new FreeDiskGuard(
            static function (string $path) use (&$measured): float {
                $measured[] = $path;

                return 900.0;
            },
            static fn (string $path): float => 1000.0,
        );

        // The producers create their subdirectories lazily; measuring a path
        // that is not there yet would refuse every artifact on a fresh install.
        $this->assertTrue($guard->allows($parent.'/sample/'));
        $this->assertSame([$parent], $measured);
    }

    public function test_an_out_of_range_threshold_falls_back_to_the_default(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float => 1000.0,
        );

        config(['nntmux_settings.covers_minimum_free_fraction' => 0]);
        $this->assertTrue($guard->allows('/covers/video'));

        config(['nntmux_settings.covers_minimum_free_fraction' => 2.5]);
        $this->assertTrue($guard->allows('/covers/video'));
    }
}
