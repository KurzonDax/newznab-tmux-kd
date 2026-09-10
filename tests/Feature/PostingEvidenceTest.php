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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\Par2Fixture;
use Tests\TestCase;

class PostingEvidenceTest extends TestCase
{
    #[DataProvider('multipartCases')]
    public function test_recovery_first_multipart_volume_requires_the_complete_file(string $alteration): void
    {
        [$files, $responses] = $this->fixture();
        $metadata = Par2Fixture::metadata(['first.mkv' => str_repeat('a', 20000), 'second.mkv' => 'b']);
        $volume = Par2Fixture::packet(substr($metadata, 32, 16), "PAR 2.0\0RecvSlic", pack('V', 0).str_repeat('R', 830540)).$metadata;
        $segments = [];
        $name = 'Bundle.vol000+001.par2';
        $split = 716800;
        if ($alteration === 'valid-short-crc') {
            while (! str_starts_with(hash('crc32b', substr($volume, 0, $split)), '0') && $split < 717800) {
                $split++;
            }
            $this->assertLessThan(717800, $split, 'Fixture must contain a CRC with a leading zero.');
        }
        $chunks = [substr($volume, 0, $split), substr($volume, $split)];
        $offset = 1;
        foreach ($chunks as $index => $chunk) {
            $part = $index + 1;
            $id = '<volume-part-'.$part.'@example.invalid>';
            $segments[] = ['number' => $part, 'messageid' => $id, 'bytes' => strlen($chunk)];
            $responses['HEAD'.$id] = "From: Synthetic Poster A\r\nSubject: [04/04] - \"{$name}\" yEnc ({$part}/2) 76296\r\n"
                ."Date: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            $body = (new YencService)->encode($chunk, $name);
            $end = $offset + strlen($chunk) - 1;
            $body = preg_replace('/^=ybegin[^\r\n]*\r?\n/',
                '=ybegin part='.$part.' total=2 line=128 size='.strlen($volume).' name='.$name."\r\n=ypart begin={$offset} end={$end}\r\n", $body);
            $body = preg_replace('/^=yend[^\r\n]*/m', '=yend size='.strlen($chunk).' part='.$part.' pcrc32='.hash('crc32b', $chunk), $body);
            if ($alteration === 'valid-short-crc') {
                $body = preg_replace('/pcrc32=0+/', 'pcrc32=', $body);
            }
            $responses['BODY'.$id] = $body;
            $offset = $end + 1;
        }
        foreach ($files as $index => $file) {
            $subject = str_replace('/03]', '/04]', $file->subject);
            $files[$index] = new PostingFile($file->sourceId, $file->fileId, $subject, $file->group, $file->poster, $file->date, 1, $file->segments);
            $responses['HEAD'.$file->firstArticle()] = str_replace('/03]', '/04]', $responses['HEAD'.$file->firstArticle()]);
        }
        $files[] = new PostingFile('volume', 'volume', '[04/04] - "'.$name.'" yEnc (1/2)',
            'alt.binaries.boneless', 'Synthetic Poster A', $alteration === 'head-window' ? 1767270600 : 1767268800, 2, $segments);
        if ($alteration === 'head-window') {
            $responses['HEAD<volume-part-1@example.invalid>'] = str_replace('12:00:00', '12:30:00', $responses['HEAD<volume-part-1@example.invalid>']);
            $responses['HEAD<volume-part-2@example.invalid>'] = str_replace('12:00:00', '13:00:00', $responses['HEAD<volume-part-2@example.invalid>']);
        }
        $bodyKey = 'BODY<volume-part-2@example.invalid>';
        $responses[$bodyKey] = match ($alteration) {
            'offset' => str_replace('begin=716801', 'begin=716800', $responses[$bodyKey]),
            'part' => str_replace('part=2', 'part=1', $responses[$bodyKey]),
            'total' => str_replace('total=2', 'total=3', $responses[$bodyKey]),
            'crc' => preg_replace('/pcrc32=[a-f0-9]+/', 'pcrc32=00000000', $responses[$bodyKey]),
            'whole-crc' => str_replace(' pcrc32=', ' crc32=00000000 pcrc32=', $responses[$bodyKey]),
            'short-whole-crc' => str_replace(' pcrc32=', ' crc32=0 pcrc32=', $responses[$bodyKey]),
            'name' => str_replace($name, strtolower($name), $responses[$bodyKey]),
            default => $responses[$bodyKey],
        };
        $requests = [];
        if ($alteration === 'valid') {
            $interrupted = $responses;
            unset($interrupted[$bodyKey]);
            try {
                (new PostingEvidence($this->pool($interrupted, $requests), new YencService))
                    ->resolve($files, 'multipart', microtime(true) + 60);
                $this->fail('A missing second article cannot finish the acquisition.');
            } catch (\RuntimeException) {
                $this->assertSame(1, array_count_values($requests)['BODY<volume-part-1@example.invalid>']);
            }
        }
        $decision = (new PostingEvidence($this->pool($responses, $requests), new YencService))
            ->resolve($files, 'multipart', microtime(true) + 60);
        $this->assertCount(str_starts_with($alteration, 'valid') ? 4 : 3, $decision->accepted);
        $this->assertSame(str_starts_with($alteration, 'valid') ? 100.0 : 75.0, $decision->completion());
        $this->assertContains('HEAD<volume-part-2@example.invalid>', $requests);
        if ($alteration !== 'head-window') {
            $this->assertContains('BODY<volume-part-2@example.invalid>', $requests);
        }
        $this->assertSame(1, array_count_values($requests)['BODY<volume-part-1@example.invalid>']);
        if ($alteration === 'offset') {
            $this->assertSame('contradictory_yenc', $decision->unresolved['volume']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function multipartCases(): iterable
    {
        foreach (['valid', 'valid-short-crc', 'offset', 'part', 'total', 'crc', 'whole-crc', 'short-whole-crc', 'head-window', 'name'] as $case) {
            yield $case => [$case];
        }
    }

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
            if (! isset($responses[$key])) {
                return new BoundedArticleResponse(null, 0, 'article_missing');
            }

            return new BoundedArticleResponse($responses[$key], strlen($responses[$key]));
        });

        return new NntpProviderPool([$this->provider(1)], clientFactory: static fn () => $client);
    }

    private function provider(int $id): NntpProvider
    {
        return new NntpProvider($id, 'fixture-'.$id, 'example.invalid', 119, false, '', '', 1, 5, true);
    }
}
