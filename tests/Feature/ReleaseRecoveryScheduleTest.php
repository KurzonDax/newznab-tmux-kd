<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseRecoveryScheduleTest extends TestCase
{
    #[Test]
    public function bounded_repair_and_rescan_passes_run_hourly_in_recovery_order_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());
        $repairIndex = $events->search(
            static fn (Event $event): bool => str_contains($event->command ?? '', 'releases:repair-completion'),
        );
        $rescanIndex = $events->search(
            static fn (Event $event): bool => str_contains($event->command ?? '', 'releases:rescan-missing-files'),
        );

        $this->assertIsInt($repairIndex);
        $this->assertIsInt($rescanIndex);
        $this->assertLessThan($rescanIndex, $repairIndex, 'Segment repair must be registered before whole-file rescan.');

        $rescan = $events->get($rescanIndex);

        $this->assertInstanceOf(Event::class, $rescan);
        $this->assertSame('0 * * * *', $rescan->expression);
        $this->assertTrue($rescan->withoutOverlapping);
    }

    #[Test]
    public function bounded_search_maintenance_uses_the_configured_schedule_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            static fn (Event $event): bool => str_contains($event->command ?? '', 'nntmux:search-maintain'),
        );

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame((string) config('search.reconciliation.cron'), $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
