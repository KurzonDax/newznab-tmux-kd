<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NntpProvider;
use App\Services\Runners\BinariesRunner;
use Illuminate\Support\Facades\DB;
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
            ['provider' => 'super', 'group' => 'alt.alpha', 'ranges' => [[101, 110], [111, 120], [121, 130]]],
            ['provider' => 'super', 'group' => 'alt.bravo', 'ranges' => [[201, 204], [215, 220], [221, 230]]],
        ], $ranges);
    }

    public function test_a_remainder_ends_at_the_servers_newest_article(): void
    {
        $ranges = (new BinariesRunner)->secondaryRanges($this->provider(), self::CHECKED_SINCE, 100, 10);
        $alpha = array_values(array_filter($ranges, static fn (array $entry): bool => $entry['group'] === 'alt.alpha'))[0];

        $this->assertSame([[101, 110], [111, 120], [121, 130], [131, 135]], $alpha['ranges']);
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
