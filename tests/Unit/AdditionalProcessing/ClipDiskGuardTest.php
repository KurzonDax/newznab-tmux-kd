<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\ClipDiskGuard;
use PHPUnit\Framework\TestCase;

class ClipDiskGuardTest extends TestCase
{
    public function test_it_allows_clips_at_or_above_ten_percent_free(): void
    {
        $guard = new ClipDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertTrue($guard->allows('/covers/video'));
    }

    public function test_it_refuses_clips_below_ten_percent_free(): void
    {
        $guard = new ClipDiskGuard(
            static fn (string $path): float => 99.0,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows('/covers/video'));
    }

    public function test_unreadable_disk_metrics_refuse_the_clip(): void
    {
        $guard = new ClipDiskGuard(
            static fn (string $path): float|false => false,
            static fn (string $path): float => 1000.0,
        );

        $this->assertFalse($guard->allows('/covers/video'));

        $guard = new ClipDiskGuard(
            static fn (string $path): float => 100.0,
            static fn (string $path): float|false => false,
        );

        $this->assertFalse($guard->allows('/covers/video'));
    }
}
