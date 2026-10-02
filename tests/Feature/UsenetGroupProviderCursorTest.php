<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroupProviderCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class UsenetGroupProviderCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 14:30:00');
        config(['app.timezone' => 'UTC']);

        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_group_provider_cursors');
        $tables->create('usenet_group_provider_ingested_ranges');

        foreach (['super', 'other'] as $provider) {
            DB::table('usenet_group_provider_cursors')->insert([
                'usenet_groups_id' => 1, 'provider' => $provider, 'provider_host' => $provider.'.example.com',
                'last_record' => 1_000, 'last_record_postdate' => '2026-08-16 00:00:00', 'last_advanced_at' => null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_range_ahead_of_the_cursor_is_parked_then_absorbed_when_the_gap_fills(): void
    {
        $this->assertSame(0, UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1101, 1200, (int) strtotime('2026-08-17 12:00:00')));
        $this->assertSame(1000, $this->cursor('super')->last_record);
        $this->assertNull($this->cursor('super')->last_advanced_at);
        $this->assertDatabaseHas('usenet_group_provider_ingested_ranges', [
            'usenet_groups_id' => 1, 'provider' => 'super', 'first_record' => 1101, 'last_record' => 1200,
        ]);

        UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1100, (int) strtotime('2026-08-17 11:00:00'));

        $cursor = $this->cursor('super');
        $this->assertSame(1200, $cursor->last_record);
        $this->assertSame('2026-08-17 12:00:00', $cursor->last_record_postdate);
        $this->assertSame('2026-08-17 14:30:00', $cursor->last_advanced_at);
        $this->assertSame(0, DB::table('usenet_group_provider_ingested_ranges')->count());
    }

    public function test_the_cursor_never_moves_backwards_and_only_stamps_an_increase(): void
    {
        UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1200, (int) strtotime('2026-08-17 12:00:00'));
        Carbon::setTestNow('2026-08-17 15:00:00');

        $this->assertSame(0, UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1150, (int) strtotime('2026-08-17 11:30:00')));
        $this->assertSame(0, UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1200, null));

        $cursor = $this->cursor('super');
        $this->assertSame(1200, $cursor->last_record);
        $this->assertSame('2026-08-17 12:00:00', $cursor->last_record_postdate);
        $this->assertSame('2026-08-17 14:30:00', $cursor->last_advanced_at);
    }

    public function test_a_missing_cursor_row_writes_nothing(): void
    {
        DB::table('usenet_group_provider_cursors')->where('provider', 'super')->delete();

        $this->assertSame(0, UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1100, null));
        $this->assertSame(0, UsenetGroupProviderCursor::advanceContiguously(1, 'super', 5001, 5100, null));

        $this->assertSame(0, DB::table('usenet_group_provider_ingested_ranges')->count());
        $this->assertNull(UsenetGroupProviderCursor::query()->where('provider', 'super')->first());
    }

    public function test_two_providers_keep_independent_cursors_for_one_group(): void
    {
        UsenetGroupProviderCursor::advanceContiguously(1, 'other', 1201, 1300, null);
        UsenetGroupProviderCursor::advanceContiguously(1, 'super', 1001, 1200, null);

        $this->assertSame(1200, $this->cursor('super')->last_record);
        $this->assertSame(1000, $this->cursor('other')->last_record);
        $this->assertSame(
            [['other', 1201]],
            DB::table('usenet_group_provider_ingested_ranges')->get(['provider', 'first_record'])
                ->map(fn (object $row): array => [$row->provider, (int) $row->first_record])->all(),
        );

        UsenetGroupProviderCursor::advanceContiguously(1, 'other', 1001, 1200, null);

        $this->assertSame(1300, $this->cursor('other')->last_record);
        $this->assertSame(0, DB::table('usenet_group_provider_ingested_ranges')->count());
    }

    public function test_a_cursor_at_zero_starts_wherever_the_first_range_begins(): void
    {
        DB::table('usenet_group_provider_cursors')->where('provider', 'super')->update(['last_record' => 0]);

        UsenetGroupProviderCursor::advanceContiguously(1, 'super', 5001, 5100, null);

        $this->assertSame(5100, $this->cursor('super')->last_record);
    }

    private function cursor(string $provider): UsenetGroupProviderCursor
    {
        return UsenetGroupProviderCursor::query()->where('usenet_groups_id', 1)->where('provider', $provider)->firstOrFail();
    }
}
