<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionReconciliation\PostingEvidence;
use App\Services\CollectionReconciliation\PostingFile;
use App\Services\CollectionReconciliation\TrafficBudget;
use App\Services\NNTP\Contracts\BoundedProviderClient;
use App\Services\NNTP\DTO\BoundedArticleResponse;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NntpProviderPool;
use App\Services\YencService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\TestCase;

class PostingEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        (require database_path('migrations/2026_09_08_121907_create_collection_reconciliation_tables.php'))->up();
        $this->freezeSecond();
    }

    public function test_shared_cache_expires_seven_days_after_acquisition_even_in_a_long_running_worker(): void
    {
        [$files, $responses] = $this->fixture();
        $requests = [];
        $pool = $this->pool($responses, $requests);
        $first = new PostingEvidence($pool, new YencService);
        $this->assertCount(3, $first->resolve($files, 'first', microtime(true) + 60)->accepted);
        $this->assertCount(4, $requests, 'Only HEADs and base PAR2 BODY are needed for unique names.');
        $this->travel(6)->days();
        $other = new PostingEvidence($pool, new YencService);
        $other->resolve($files, 'other', microtime(true) + 60);
        $this->assertCount(4, $requests);
        $this->travel(2)->days();
        $other->resolve($files, 'expired', microtime(true) + 60);
        $this->assertCount(8, $requests, 'Loading from shared cache must not extend its original expiry.');
    }

    public function test_selective_probes_stop_at_four_and_short_prefixes_leave_duplicates_ambiguous(): void
    {
        [$files, $responses] = $this->fixture();
        $first = $files[0];
        for ($i = 0; $i < 5; $i++) {
            $id = '<duplicate-'.$i.'@example.invalid>';
            $files[] = new PostingFile('duplicate-'.$i, 'duplicate-'.$i, $first->subject, $first->group, $first->poster, $first->date, 1,
                [['number' => 1, 'messageid' => $id, 'bytes' => 20000]]);
            $responses['HEAD'.$id] = str_replace($first->firstArticle(), $id, $responses['HEAD'.$first->firstArticle()]);
            $responses['BODY'.$id] = $responses['BODY'.$first->firstArticle()];
        }
        $requests = [];
        $evidence = new PostingEvidence($this->pool($responses, $requests), new YencService);
        $decision = $evidence->resolve($files, 'selective', microtime(true) + 60);
        $this->assertCount(2, $decision->accepted);
        $this->assertSame('ambiguous_payload', $decision->unresolved['0']);
        $payloadRequests = array_filter($requests, static fn ($request): bool => str_starts_with($request, 'BODY') && ! str_contains($request, 'file-2'));
        $this->assertCount(4, $payloadRequests);
        $this->assertLessThan(100, $decision->completion());
    }

    public function test_failover_and_transport_exceptions_consume_the_same_decision_budget(): void
    {
        config(['collection-reconciliation.decision_bytes' => 200]);
        $a = Mockery::mock(BoundedProviderClient::class);
        $a->shouldReceive('fetchBoundedArticle')->once()->andThrow(new \RuntimeException('timeout after unknown bytes'));
        $b = Mockery::mock(BoundedProviderClient::class);
        $b->shouldReceive('fetchBoundedArticle')->once()->andReturn(new BoundedArticleResponse('ok', 30));
        $pool = new NntpProviderPool([$this->provider(1), $this->provider(2)], clientFactory: static fn ($provider) => $provider->position === 1 ? $a : $b);
        $result = $pool->fetchBoundedArticle('<failover@example.invalid>', true, 100, microtime(true) + 10, new TrafficBudget('failover'));
        $this->assertSame('ok', $result->data);
        $this->assertSame(130, $result->bytes);
        $this->assertSame(130, (int) DB::table('reconciliation_traffic')->where('bucket', 'decision:failover')->value('actual'));
        $this->assertFalse((new TrafficBudget('failover'))->reserve(71));
    }

    /** @return array{list<PostingFile>, array<string, string>} */
    private function fixture(): array
    {
        $data = str_repeat('a', 20000);
        $payloads = ['first.mkv' => $data, 'second.mkv' => 'b'];
        $payloads['Bundle.par2'] = Par2Fixture::metadata($payloads);
        $files = $responses = [];
        foreach ($payloads as $name => $payload) {
            $i = count($files);
            $id = '<file-'.$i.'@example.invalid>';
            $subject = sprintf('[%02d/03] - "%s" yEnc (1/1)', $i + 1, $name);
            $files[] = new PostingFile((string) $i, (string) $i, $subject, 'alt.binaries.boneless', 'Synthetic Poster A', 1767268800, 1,
                [['number' => 1, 'messageid' => $id, 'bytes' => strlen($payload)]]);
            $responses['HEAD'.$id] = "fRoM: Synthetic Poster A\r\nSUBJECT: {$subject}\r\ndate: Thu, 01 Jan 2026 12:00:00 +0000\r\nmessage-id: {$id}\r\n";
            $responses['BODY'.$id] = (new YencService)->encode($payload, $name);
        }
        $short = (new YencService)->encode(substr($data, 0, 1024), 'first.mkv');
        $short = preg_replace('/^=ybegin[^\r\n]*\r?\n/', "=ybegin part=1 total=2 line=128 size=20000 name=first.mkv\r\n=ypart begin=1 end=1024\r\n", $short);
        $responses['BODY<file-0@example.invalid>'] = $short;

        return [$files, $responses];
    }

    /**
     * @param  array<string, string>  $responses
     * @param  list<string>  $requests
     */
    private function pool(array $responses, array &$requests): NntpProviderPool
    {
        $client = Mockery::mock(BoundedProviderClient::class);
        $client->shouldReceive('fetchBoundedArticle')->andReturnUsing(static function ($id, $head) use ($responses, &$requests): BoundedArticleResponse {
            $key = ($head ? 'HEAD' : 'BODY').$id;
            $requests[] = $key;

            return new BoundedArticleResponse($responses[$key], strlen($responses[$key]));
        });

        return new NntpProviderPool([$this->provider(1)], clientFactory: static fn () => $client);
    }

    private function provider(int $id): NntpProvider
    {
        return new NntpProvider($id, 'fixture-'.$id, 'example.invalid', 119, false, '', '', 1, 5, true);
    }
}
