<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tmux\TmuxDisplaySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TmuxDisplaySnapshotTest extends TestCase
{
    public function test_restart_expiry_and_failure_preserve_last_success_and_timestamp(): void
    {
        $cache = new Repository(new ArrayStore);
        $calls = 0;
        $capture = function () use (&$calls): array {
            $calls++;

            return ['releases' => 42];
        };
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        try {
            $first = (new TmuxDisplaySnapshot($cache))->get('test', $capture);
            $this->assertSame($first, (new TmuxDisplaySnapshot($cache))->get('test', $capture));
            $this->assertSame(1, $calls);
            CarbonImmutable::setTestNow('2026-09-10 12:05:00');
            $failed = fn (): array => throw new RuntimeException('database unavailable');
            $this->assertSame($first, (new TmuxDisplaySnapshot($cache))->get('test', $failed));
            $this->assertNull((new TmuxDisplaySnapshot($cache))->get('empty', $failed));
            $lock = $cache->lock('tmux:display:test:refresh', 60);
            $this->assertTrue($lock->get());
            $this->assertSame($first, (new TmuxDisplaySnapshot($cache))->get('test', $capture));
            $this->assertSame(1, $calls);
            $lock->release();
            $next = (new TmuxDisplaySnapshot($cache))->get('test', $capture);
            $this->assertSame(2, $calls);
            $this->assertNotSame($first['observed_at'], $next['observed_at']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
