<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NntpProvider;
use App\Services\Runners\BinariesRunner;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class BinariesRunnerSecondaryRangesTest extends TestCase
{
    private const string CHECKED_SINCE = '2026-10-02 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'name', 'active']);
        $tables->create('usenet_group_provider_cursors');
        $tables->create('usenet_group_provider_ingested_ranges');

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.alpha', 'active' => 1],
            ['id' => 2, 'name' => 'alt.bravo', 'active' => 1],
            ['id' => 3, 'name' => 'alt.stale', 'active' => 1],
            ['id' => 4, 'name' => 'alt.moved', 'active' => 1],
            ['id' => 5, 'name' => 'alt.inactive', 'active' => 0],
            ['id' => 6, 'name' => 'alt.current', 'active' => 1],
        ]);
        DB::table('usenet_group_provider_cursors')->insert([
            $this->cursor(1, 100, 135),
            $this->cursor(2, 200, 1_000),
            $this->cursor(3, 300, 1_000, checkedAt: '2026-10-02 11:59:59'),
            $this->cursor(4, 400, 1_000, host: 'old.example.invalid'),
            $this->cursor(5, 500, 1_000),
            $this->cursor(6, 600, 600),
            // Another provider's cursor on the same group is not this provider's work.
            ['usenet_groups_id' => 1, 'provider' => 'other', 'provider_host' => 'other.example.invalid', 'last_record' => 1,
                'server_last' => 1_000, 'server_checked_at' => self::CHECKED_SINCE],
        ]);
        DB::table('usenet_group_provider_ingested_ranges')->insert([
            ['usenet_groups_id' => 2, 'provider' => 'super', 'first_record' => 205, 'last_record' => 214],
            ['usenet_groups_id' => 2, 'provider' => 'other', 'first_record' => 221, 'last_record' => 230],
        ]);
    }

    public function test_it_slices_each_live_cursor_up_to_the_servers_newest_article_less_parked_ranges(): void
    {
        $ranges = (new BinariesRunner)->secondaryRanges($this->provider(), self::CHECKED_SINCE, 30, 10);

        $this->assertEqualsCanonicalizing([
            ['provider' => 'super', 'group' => 'alt.alpha', 'ranges' => [[126, 135], [116, 125], [106, 115]]],
            ['provider' => 'super', 'group' => 'alt.bravo', 'ranges' => [[991, 1000], [981, 990], [971, 980]]],
        ], $ranges);
    }

    public function test_a_remainder_ends_at_the_servers_newest_article(): void
    {
        $ranges = (new BinariesRunner)->secondaryRanges($this->provider(), self::CHECKED_SINCE, 100, 10);
        $alpha = array_values(array_filter($ranges, static fn (array $entry): bool => $entry['group'] === 'alt.alpha'))[0];

        $this->assertSame([[126, 135], [116, 125], [106, 115], [101, 105]], $alpha['ranges']);
    }

    /**
     * @param  list<array{int, int}>  $parked
     * @param  list<array{int, int}>  $expected
     */
    #[DataProvider('newestFirstCases')]
    public function test_it_reads_the_newest_unread_articles_first_within_the_budget(int $last, int $serverLast, array $parked, array $expected): void
    {
        $this->assertSame($expected, $this->alphaRanges($last, $serverLast, $parked, maxHeaders: 250, maxMessages: 100));
    }

    /** @return array<string, array{int, int, list<array{int, int}>, list<array{int, int}>}> */
    public static function newestFirstCases(): array
    {
        return [
            'newest first' => [100, 1_000, [], [[901, 1000], [801, 900], [751, 800]]],
            'parked ranges skipped' => [100, 1_000, [[901, 1000]], [[801, 900], [701, 800], [651, 700]]],
            'each interval cut from its top' => [100, 1_000, [[851, 950]], [[951, 1000], [751, 850], [651, 750]]],
            'near the tip' => [950, 1_000, [], [[951, 1000]]],
        ];
    }

    public function test_no_range_reads_past_the_servers_newest_article_or_below_the_cursor(): void
    {
        foreach ([[100, 1_000, []], [100, 1_000, [[901, 1000]]], [100, 1_000, [[102, 160], [700, 999]]], [950, 1_000, []], [100, 137, [[110, 120]]]] as [$last, $serverLast, $parked]) {
            foreach ([[250, 100], [7, 3], [1_000, 1_000], [31, 10]] as [$maxHeaders, $maxMessages]) {
                $ranges = $this->alphaRanges($last, $serverLast, $parked, $maxHeaders, $maxMessages);
                $read = 0;
                foreach ($ranges as [$start, $end]) {
                    $this->assertGreaterThanOrEqual($last + 1, $start);
                    $this->assertLessThanOrEqual($serverLast, $end);
                    $this->assertLessThanOrEqual($maxMessages, $end - $start + 1);
                    $read += $end - $start + 1;
                }
                $parkedArticles = array_sum(array_map(static fn (array $range): int => $range[1] - $range[0] + 1, $parked));
                $this->assertSame(min($serverLast - $last - $parkedArticles, $serverLast - $last, $maxHeaders), $read);
            }
        }
    }

    /**
     * alt.alpha's ranges for a `super` cursor at $last, with only the given parked ranges.
     *
     * @param  list<array{int, int}>  $parked
     * @return list<array{int, int}>
     */
    private function alphaRanges(int $last, int $serverLast, array $parked, int $maxHeaders, int $maxMessages): array
    {
        DB::table('usenet_group_provider_cursors')->delete();
        DB::table('usenet_group_provider_ingested_ranges')->delete();
        DB::table('usenet_group_provider_cursors')->insert($this->cursor(1, $last, $serverLast));
        foreach ($parked as [$first, $parkedLast]) {
            DB::table('usenet_group_provider_ingested_ranges')->insert(
                ['usenet_groups_id' => 1, 'provider' => 'super', 'first_record' => $first, 'last_record' => $parkedLast],
            );
        }

        $ranges = (new BinariesRunner)->secondaryRanges($this->provider(), self::CHECKED_SINCE, $maxHeaders, $maxMessages);
        $this->assertCount(1, $ranges);

        return $ranges[0]['ranges'];
    }

    private function provider(): NntpProvider
    {
        return NntpProvider::fromConfig(['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid']);
    }

    /** @return array<string, mixed> */
    private function cursor(int $groupId, int $last, int $serverLast, string $checkedAt = self::CHECKED_SINCE, string $host = 'super.example.invalid'): array
    {
        return ['usenet_groups_id' => $groupId, 'provider' => 'super', 'provider_host' => $host, 'last_record' => $last,
            'server_last' => $serverLast, 'server_checked_at' => $checkedAt];
    }
}
