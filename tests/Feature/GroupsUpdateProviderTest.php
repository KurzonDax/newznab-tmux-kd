<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\ArticleTimeLocator;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class GroupsUpdateProviderTest extends TestCase
{
    private const int SERVER_FIRST = 100_000;

    private const int SERVER_LAST = 1_000_000;

    /** The article posted exactly at the start time on the fake server. */
    private const int ARTICLE_AT_START = 600_000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-02 12:00:00');
        config(['app.timezone' => 'UTC', 'nntmux_nntp.providers' => [
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid'],
            ['position' => 3, 'name' => 'spare', 'host' => 'spare.example.invalid', 'enabled' => false],
        ]]);
        NntpProviderPool::forgetConfiguredProviders();

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill']);
        $tables->create('short_groups');
        $tables->create('usenet_group_provider_cursors');
        $tables->create('usenet_group_provider_ingested_ranges');

        DB::table('settings')->insert(['name' => 'secondary_header_start_hours', 'value' => '36']);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.known', 'active' => 1, 'backfill' => 0],
            ['id' => 2, 'name' => 'alt.new', 'active' => 1, 'backfill' => 0],
            ['id' => 3, 'name' => 'alt.moved', 'active' => 1, 'backfill' => 0],
            ['id' => 4, 'name' => 'alt.inactive', 'active' => 0, 'backfill' => 1],
            ['id' => 5, 'name' => 'alt.unlisted', 'active' => 1, 'backfill' => 0],
            ['id' => 6, 'name' => 'alt.broken', 'active' => 1, 'backfill' => 0],
        ]);
        DB::table('short_groups')->insert(['id' => 1, 'name' => 'alt.known', 'first_record' => 7, 'last_record' => 9, 'updated' => '2026-10-01 00:00:00']);
        DB::table('usenet_group_provider_cursors')->insert([
            $this->cursorRow(1, 'super.example.invalid', 900_000),
            $this->cursorRow(3, 'old-backbone.example.invalid', 77),
        ]);
        DB::table('usenet_group_provider_ingested_ranges')->insert([
            ['usenet_groups_id' => 1, 'provider' => 'super', 'first_record' => 900_100, 'last_record' => 900_200],
            ['usenet_groups_id' => 3, 'provider' => 'super', 'first_record' => 100, 'last_record' => 200],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();

        parent::tearDown();
    }

    public function test_it_refreshes_known_positions_and_initialises_missing_or_moved_cursors(): void
    {
        $this->app->instance(NNTPService::class, $this->server());

        $this->artisan('groups:update', ['--provider' => 'super'])->assertSuccessful();

        $known = $this->cursor(1);
        $this->assertSame(900_000, (int) $known->last_record);
        $this->assertSame([self::SERVER_FIRST, self::SERVER_LAST + 1], [(int) $known->server_first, (int) $known->server_last]);
        $this->assertSame('2026-10-02 12:00:00', $known->server_checked_at);
        $this->assertSame(1, DB::table('usenet_group_provider_ingested_ranges')->where('usenet_groups_id', 1)->count());

        $located = (new ArticleTimeLocator)->locate($this->server(), ['first' => self::SERVER_FIRST, 'last' => self::SERVER_LAST], $this->startTime());
        foreach ([2, 3] as $groupId) {
            $cursor = $this->cursor($groupId);
            $this->assertSame($located - 1, (int) $cursor->last_record);
            $this->assertSame('super.example.invalid', $cursor->provider_host);
            $this->assertSame('2026-10-01 00:00:00', $cursor->last_record_postdate);
            $this->assertSame('2026-10-02 12:00:00', $cursor->last_advanced_at);
            $this->assertSame([self::SERVER_FIRST, self::SERVER_LAST + $groupId], [(int) $cursor->server_first, (int) $cursor->server_last]);
            $this->assertSame('2026-10-02 12:00:00', $cursor->server_checked_at);
        }
        $this->assertLessThanOrEqual(2_000, self::ARTICLE_AT_START - $located);
        $this->assertSame(0, DB::table('usenet_group_provider_ingested_ranges')->where('usenet_groups_id', 3)->count());

        // A group whose locator call fails is skipped; inactive and unlisted groups are not read.
        foreach ([4, 5, 6] as $groupId) {
            $this->assertNull($this->cursor($groupId));
        }
        $this->assertSame([['alt.known', 7, 9]], DB::table('short_groups')->get()
            ->map(fn (object $row): array => [$row->name, (int) $row->first_record, (int) $row->last_record])->all());
    }

    public function test_an_unknown_or_disabled_provider_fails(): void
    {
        foreach (['nowhere', 'spare'] as $name) {
            $this->artisan('groups:update', ['--provider' => $name])
                ->expectsOutputToContain('Unknown or disabled NNTP provider: '.$name)
                ->assertFailed();
        }

        $this->assertSame(1, DB::table('short_groups')->count());
    }

    private function startTime(): int
    {
        return Carbon::parse('2026-10-01 00:00:00')->getTimestamp();
    }

    /** @return NNTPService&MockInterface */
    private function server(): NNTPService
    {
        $selected = null;
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('useProvider')->withArgs(static fn (NntpProvider $provider, bool $poolFailover): bool => $provider->name === 'super' && ! $poolFailover);
        $nntp->shouldReceive('provider')->andReturn(NntpProviderPool::headerProviderNamed('super'));
        $nntp->shouldReceive('doConnect')->andReturn(true);
        $nntp->shouldReceive('doQuit')->andReturn(true);
        $nntp->shouldReceive('getGroups')->andReturn(array_map(
            static fn (int $id, string $name): array => ['group' => $name, 'first' => (string) self::SERVER_FIRST, 'last' => (string) (self::SERVER_LAST + $id)],
            [1, 2, 3, 4, 6, 7],
            ['alt.known', 'alt.new', 'alt.moved', 'alt.inactive', 'alt.broken', 'alt.elsewhere'],
        ));
        $nntp->shouldReceive('selectGroup')->andReturnUsing(static function (string $group) use (&$selected): array {
            $selected = $group;

            return ['group' => $group, 'first' => self::SERVER_FIRST, 'last' => self::SERVER_LAST, 'count' => self::SERVER_LAST - self::SERVER_FIRST];
        });
        $startTime = $this->startTime();
        $nntp->shouldReceive('getXOVER')->andReturnUsing(static function (string $range) use (&$selected, $startTime): mixed {
            if ($selected === 'alt.broken') {
                return new Error('Connection lost', 400);
            }
            [$from, $to] = array_map('intval', explode('-', $range));
            $lines = [];
            for ($article = $from; $article <= min($to, self::SERVER_LAST); $article++) {
                $lines[] = ['Number' => (string) $article, 'Date' => gmdate('D, d M Y H:i:s', $startTime + ($article - self::ARTICLE_AT_START) * 60).' GMT'];
            }

            return $lines;
        });

        return $nntp;
    }

    private function cursor(int $groupId): ?object
    {
        return DB::table('usenet_group_provider_cursors')->where('usenet_groups_id', $groupId)->where('provider', 'super')->first();
    }

    /** @return array<string, mixed> */
    private function cursorRow(int $groupId, string $host, int $lastRecord): array
    {
        return [
            'usenet_groups_id' => $groupId, 'provider' => 'super', 'provider_host' => $host,
            'last_record' => $lastRecord, 'last_record_postdate' => '2026-10-02 10:00:00',
            'last_advanced_at' => '2026-10-02 10:00:00', 'server_first' => 1, 'server_last' => 2,
            'server_checked_at' => '2026-10-01 00:00:00',
        ];
    }
}
