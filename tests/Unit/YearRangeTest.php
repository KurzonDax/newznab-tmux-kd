<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\YearRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YearRangeTest extends TestCase
{
    #[Test]
    public function it_resolves_a_single_year(): void
    {
        $range = YearRange::fromInput('1975');

        $this->assertNotNull($range);
        $this->assertSame(1975, $range->from);
        $this->assertSame(1975, $range->to);
    }

    #[Test]
    public function it_resolves_a_decade_as_an_inclusive_ten_year_span(): void
    {
        $range = YearRange::fromInput('1970s');

        $this->assertNotNull($range);
        $this->assertSame(1970, $range->from);
        $this->assertSame(1979, $range->to);
    }

    #[Test]
    public function it_resolves_bounded_and_open_custom_ranges(): void
    {
        $bounded = YearRange::fromInput('custom', '1970', '1975');
        $openStart = YearRange::fromInput('custom', '', '1975');
        $openEnd = YearRange::fromInput('custom', '1970', '');

        $this->assertSame([1970, 1975], [$bounded?->from, $bounded?->to]);
        $this->assertSame([null, 1975], [$openStart?->from, $openStart?->to]);
        $this->assertSame([1970, null], [$openEnd?->from, $openEnd?->to]);
    }

    #[Test]
    public function it_rejects_invalid_or_reversed_ranges(): void
    {
        $this->assertNull(YearRange::fromInput('custom', '', ''));
        $this->assertNull(YearRange::fromInput('custom', '1980', '1970'));
        $this->assertNull(YearRange::fromInput('not-a-year'));
        $this->assertNull(YearRange::fromInput(['1975']));
    }

    #[Test]
    public function it_lists_decades_before_the_current_decade(): void
    {
        $this->assertSame(
            ['1900s', '1910s', '1920s', '1930s', '1940s', '1950s', '1960s', '1970s', '1980s', '1990s', '2000s', '2010s', '2020s'],
            YearRange::decades(2026),
        );
    }
}
