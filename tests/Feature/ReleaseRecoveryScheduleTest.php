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
    public function neither_recovery_pass_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(static fn (Event $event): string => $event->command ?? '');

        $this->assertNotEmpty($commands);
        foreach ($commands as $command) {
            $this->assertStringNotContainsString('releases:repair-completion', $command);
            $this->assertStringNotContainsString('releases:rescan-missing-files', $command);
        }
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
