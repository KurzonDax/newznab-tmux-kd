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

        $this->assertTrue($guard->allows());
    }

    public function test_it_refuses_artifacts_below_ten_percent_free(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float => 99.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows());
    }

    public function test_unreadable_disk_metrics_refuse_the_artifact(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float|false => false,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows());

        $guard = new FreeDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float|false => false,
        );

        $this->assertFalse($guard->allows());
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['nntmux_settings.covers_minimum_free_fraction' => 0.25]);

        $guard = new FreeDiskGuard(
            static fn (string $path): float => 200.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows());

        config(['nntmux_settings.covers_minimum_free_fraction' => 0.15]);

        $this->assertTrue($guard->allows());
    }

    public function test_an_out_of_range_threshold_falls_back_to_the_default(): void
    {
        $guard = new FreeDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float => 1000.0,
        );

        config(['nntmux_settings.covers_minimum_free_fraction' => 0]);
        $this->assertTrue($guard->allows());

        config(['nntmux_settings.covers_minimum_free_fraction' => 2.5]);
        $this->assertTrue($guard->allows());
    }
}
