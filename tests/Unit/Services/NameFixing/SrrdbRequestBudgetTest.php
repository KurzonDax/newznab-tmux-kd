<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\Srrdb\SrrdbRequestBudget;
use PHPUnit\Framework\TestCase;

class SrrdbRequestBudgetTest extends TestCase
{
    public function test_it_caps_requests_per_cycle(): void
    {
        $budget = new SrrdbRequestBudget(2, 0);

        $this->assertTrue($budget->acquire());
        $this->assertTrue($budget->acquire());
        $this->assertFalse($budget->acquire());
        $this->assertSame(2, $budget->used());
    }

    public function test_it_delays_requests_to_the_configured_rate(): void
    {
        $clockValues = [10.0, 10.25, 11.0];
        $delays = [];
        $budget = new SrrdbRequestBudget(
            2,
            1,
            static function (int $microseconds) use (&$delays): void {
                $delays[] = $microseconds;
            },
            static function () use (&$clockValues): float {
                return array_shift($clockValues) ?? 11.0;
            },
        );

        $this->assertTrue($budget->acquire());
        $this->assertTrue($budget->acquire());

        $this->assertSame([750_000], $delays);
    }
}
