<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\ArticleTimeLocator;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NNTPService;
use App\Services\NNTP\ProviderListingCanary;
use DariusIII\NetNntp\Error;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class ProviderListingCanaryTest extends TestCase
{
    private const string NOW = '2026-10-02 14:07:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        config(['app.timezone' => 'UTC']);
        $this->providers(2);
        ListingServer::$selected = [];

        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('usenet_groups', ['id', 'name', 'active', 'first_record', 'last_record', 'first_record_postdate', 'last_record_postdate']);
        (require database_path('migrations/2026_10_02_000100_create_nntp_listing_coverage.php'))->up();
        DB::table('settings')->insert(['name' => 'maxmssgs', 'value' => '20000']);
        $this->app->bind(NNTPService::class, static fn (): ListingServer => new ListingServer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NntpProviderPool::forgetConfiguredProviders();

        parent::tearDown();
    }

    public function test_it_records_each_group_and_direction_and_skips_only_a_failing_one(): void
    {
        $this->group(1, 'alt.alpha', 150_000);
        $this->group(2, 'alt.flaky', 140_000);
        DB::table('nntp_listing_coverage')->insert([
            $this->row('2026-09-01 00:07:00'), $this->row('2026-09-03 00:07:00'),
        ]);

        app(ProviderListingCanary::class)->run();

        $rows = DB::table('nntp_listing_coverage')->where('measured_at', self::NOW)->orderBy('groups_id')->orderBy('sample_provider')
            ->get(['groups_id', 'sample_provider', 'reference_provider', 'sample_time', 'sampled', 'found']);
        $this->assertSame([[1, 'primary', 'super'], [1, 'super', 'primary'], [2, 'primary', 'super']],
            $rows->map(fn (object $row): array => [(int) $row->groups_id, $row->sample_provider, $row->reference_provider])->all());
        foreach ($rows as $row) {
            [$sampled, $found] = $this->expected($row->sample_provider, $row->reference_provider, (string) DB::table('usenet_groups')->where('id', $row->groups_id)->value('name'));
            $this->assertSame('2026-10-02 11:07:00', $row->sample_time);
            $this->assertSame($sampled, (int) $row->sampled);
            $this->assertSame($found, (int) $row->found);
            $this->assertGreaterThan(15_000, $sampled);
        }
        // Provider 2 leaves out 5 of every 18 multi-segment posts, so provider 1's sample is short
        // there; provider 2's own sample is fully listed by provider 1.
        $this->assertEqualsWithDelta(13 / 18, $rows[0]->found / $rows[0]->sampled, 0.01);
        $this->assertSame((int) $rows[1]->sampled, (int) $rows[1]->found);

        // Only the expired row is pruned.
        $this->assertSame(['2026-09-03 00:07:00', self::NOW], DB::table('nntp_listing_coverage')->distinct()->orderBy('measured_at')->pluck('measured_at')->all());
    }

    public function test_it_writes_nothing_with_one_enabled_provider(): void
    {
        $this->providers(1);
        $this->group(1, 'alt.alpha', 150_000);

        app(ProviderListingCanary::class)->run();

        $this->assertSame(0, DB::table('nntp_listing_coverage')->count());
        $this->assertSame([], ListingServer::$selected);
    }

    public function test_it_measures_the_five_busiest_groups_at_or_below_the_rate_cap(): void
    {
        foreach ([300_000, 200_000, 150_000, 140_000, 130_000, 120_000, 110_000] as $index => $rate) {
            $this->group($index + 1, 'alt.refused.'.$rate, $rate);
        }
        $this->group(8, 'alt.refused.inactive', 190_000, active: false);
        $this->group(9, 'alt.refused.undated', 190_000, dated: false);

        app(ProviderListingCanary::class)->run();

        $this->assertSame(['alt.refused.200000', 'alt.refused.150000', 'alt.refused.140000', 'alt.refused.130000', 'alt.refused.120000'],
            array_values(array_unique(ListingServer::$selected)));
        $this->assertSame(0, DB::table('nntp_listing_coverage')->count());
    }

    /**
     * What the canary should record, worked out from the fake server directly.
     *
     * @return array{int, int}
     */
    private function expected(string $sampleName, string $referenceName, string $group): array
    {
        $sample = $this->server($sampleName, $group);
        $reference = $this->server($referenceName, $group);
        $locator = new ArticleTimeLocator;
        $time = Carbon::parse('2026-10-02 11:07:00')->getTimestamp();

        $start = $locator->locate($sample, $sample->range(), $time);
        $from = $locator->locate($reference, $reference->range(), $time - 7_200);
        $to = $locator->locate($reference, $reference->range(), $time + 7_200);
        $listed = [];
        foreach ($reference->linesBetween($from, $to) as $line) {
            $listed[$line['Message-ID']] = true;
        }
        $sampled = $found = 0;
        foreach ($sample->linesBetween($start, $start + 29_999) as $line) {
            if (preg_match('/\(\d+\/\d{2,}\)/', $line['Subject']) === 1) {
                $sampled++;
                $found += isset($listed[$line['Message-ID']]) ? 1 : 0;
            }
        }

        return [$sampled, $found];
    }

    private function server(string $provider, string $group): ListingServer
    {
        $server = new ListingServer;
        $server->useProvider(NntpProviderPool::headerProviderNamed($provider) ?? throw new \LogicException($provider));
        $server->selectGroup($group);

        return $server;
    }

    private function providers(int $count): void
    {
        config(['nntmux_nntp.providers' => [
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid', 'enabled' => $count >= 2],
        ]]);
        NntpProviderPool::forgetConfiguredProviders();
    }

    private function group(int $id, string $name, int $perHour, bool $active = true, bool $dated = true): void
    {
        DB::table('usenet_groups')->insert([
            'id' => $id, 'name' => $name, 'active' => $active ? 1 : 0, 'first_record' => 0, 'last_record' => $perHour * 10,
            'first_record_postdate' => $dated ? '2026-10-02 00:00:00' : null, 'last_record_postdate' => $dated ? '2026-10-02 10:00:00' : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $measuredAt): array
    {
        return ['measured_at' => $measuredAt, 'groups_id' => 1, 'sample_provider' => 'primary', 'reference_provider' => 'super',
            'sample_time' => $measuredAt, 'sampled' => 1, 'found' => 1];
    }
}

/**
 * Two providers carrying the same posts, five a second, under different article numbers.
 * Provider 2 leaves every fourth post out of its listing; every tenth post is a one-segment
 * post. Groups named alt.refused.* are refused, and provider 2 fails any XOVER of 30,000 or
 * more articles in alt.flaky.
 */
final class ListingServer extends NNTPService
{
    /** Post 0 is dated 100,000 s before the sample time; post i is i / 5 seconds later. */
    private const int EPOCH = 1_790_839_220;

    private const int POSTS = 1_000_000;

    /** @var list<string> */
    public static array $selected = [];

    private string $providerName = 'primary';

    private string $group = '';

    public function __construct() {}

    public function useProvider(NntpProvider $provider, bool $poolFailover = true): void
    {
        $this->providerName = $provider->name;
    }

    public function provider(): NntpProvider
    {
        return NntpProviderPool::headerProviderNamed($this->providerName) ?? throw new \LogicException($this->providerName);
    }

    public function doConnect(bool $compression = true): mixed
    {
        return true;
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        self::$selected[] = $group;
        $this->group = $group;
        if (str_starts_with($group, 'alt.refused.')) {
            return new Error('No such group', 411);
        }

        return ['group' => $group, ...$this->range()];
    }

    /** @return array{first: int, last: int} */
    public function range(): array
    {
        return ['first' => $this->offset(), 'last' => $this->offset() + self::POSTS - 1];
    }

    public function getXOVER(string $range): mixed
    {
        [$from, $to] = array_map('intval', explode('-', $range));
        if ($this->providerName === 'super' && $this->group === 'alt.flaky' && $to - $from + 1 >= 30_000) {
            return new Error('Connection reset', 400);
        }

        return $this->linesBetween($from, $to);
    }

    /** @return list<array{Number: string, Subject: string, Date: string, 'Message-ID': string}> */
    public function linesBetween(int $from, int $to): array
    {
        $lines = [];
        $offset = $this->offset();
        for ($article = max($from, $offset); $article <= min($to, $offset + self::POSTS - 1); $article++) {
            $post = $article - $offset;
            if ($this->providerName === 'super' && $post % 4 === 3) {
                continue;
            }
            $lines[] = [
                'Number' => (string) $article,
                'Subject' => 'post '.$post.($post % 10 === 0 ? ' yEnc (1/1)' : ' yEnc (3/40)'),
                'Date' => gmdate('D, d M Y H:i:s', self::EPOCH + intdiv($post, 5)).' GMT',
                'Message-ID' => '<'.$this->group.'-'.$post.'@example.invalid>',
            ];
        }

        return $lines;
    }

    private function offset(): int
    {
        return $this->providerName === 'super' ? 9_000_000_000 : 400_000_000;
    }

    public function __destruct() {}
}
