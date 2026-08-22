<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Runners\BinariesRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BinariesRunnerQueueTest extends TestCase
{
    #[Test]
    public function range_entries_are_interleaved_across_groups(): void
    {
        $groups = [
            (object) ['groupname' => 'alt.binaries.alpha', 'our_last' => 100, 'their_last' => 20_130],
            (object) ['groupname' => 'alt.binaries.bravo', 'our_last' => 200, 'their_last' => 20_230],
        ];

        $this->assertSame([
            1 => 'part_repair  alt.binaries.alpha',
            2 => 'part_repair  alt.binaries.bravo',
            3 => 'get_range  binaries  alt.binaries.alpha  101  110  3',
            4 => 'get_range  binaries  alt.binaries.bravo  201  210  4',
            5 => 'get_range  binaries  alt.binaries.alpha  111  120  5',
            6 => 'get_range  binaries  alt.binaries.bravo  211  220  6',
            7 => 'get_range  binaries  alt.binaries.alpha  121  130  7',
            8 => 'get_range  binaries  alt.binaries.bravo  221  230  8',
        ], (new BinariesRunner)->buildSafeBinariesQueue($groups, 30, 10));
    }

    #[Test]
    public function single_entry_cases_keep_their_existing_queue_positions(): void
    {
        $groups = [
            (object) ['groupname' => 'alt.binaries.new', 'our_last' => 0, 'their_last' => 100],
            (object) ['groupname' => 'alt.binaries.current', 'our_last' => 100, 'their_last' => 20_120],
            (object) ['groupname' => 'alt.binaries.lagged', 'our_last' => 200, 'their_last' => 20_230],
        ];

        $this->assertSame([
            1 => 'update_group_headers  alt.binaries.new',
            2 => 'update_group_headers  alt.binaries.current',
            3 => 'part_repair  alt.binaries.lagged',
            4 => 'get_range  binaries  alt.binaries.lagged  201  210  4',
        ], (new BinariesRunner)->buildSafeBinariesQueue($groups, 10, 10));
    }

    #[Test]
    public function interleaving_continues_when_groups_have_unequal_ranges_and_remainders(): void
    {
        $groups = [
            (object) ['groupname' => 'alt.binaries.alpha', 'our_last' => 100, 'their_last' => 20_135],
            (object) ['groupname' => 'alt.binaries.bravo', 'our_last' => 200, 'their_last' => 20_225],
        ];

        $this->assertSame([
            1 => 'part_repair  alt.binaries.alpha',
            2 => 'part_repair  alt.binaries.bravo',
            3 => 'get_range  binaries  alt.binaries.alpha  101  110  3',
            4 => 'get_range  binaries  alt.binaries.bravo  201  210  4',
            5 => 'get_range  binaries  alt.binaries.alpha  111  120  5',
            6 => 'get_range  binaries  alt.binaries.bravo  211  220  6',
            7 => 'get_range  binaries  alt.binaries.alpha  121  130  7',
            8 => 'get_range  binaries  alt.binaries.bravo  221  226  8',
            9 => 'get_range  binaries  alt.binaries.alpha  131  136  9',
        ], (new BinariesRunner)->buildSafeBinariesQueue($groups, 35, 10));
    }
}
