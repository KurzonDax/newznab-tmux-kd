<?php

declare(strict_types=1);

namespace App\Services\CollectionReconciliation;

use App\Services\NNTP\NntpProviderPool;
use App\Services\YencService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use UnexpectedValueException;

final class PostingEvidence
{
    public const string VERSION = 'par2-posting-v1';

    /** @var array<string, array{data: string, expires: int}> */
    private array $memory = [];

    public function __construct(private readonly NntpProviderPool $pool, private readonly YencService $decoder) {}

    /** @param list<PostingFile> $files */
    public function resolve(array $files, string $budgetId, float $deadline, bool $persist = true): PostingDecision
    {
        if (count($files) > 1024 || count(array_unique(array_column($files, 'sourceId'))) > 256) {
            return (new PostingResolver)->resolve($files, [], []);
        }
        $budget = new TrafficBudget($budgetId);
        $heads = $metadata = $probes = $names = [];
        $bases = array_values(array_filter($files, static fn (PostingFile $file): bool => $file->isBasePar2()));
        if (count($bases) !== 1) {
            return (new PostingResolver)->resolve($files, [], []);
        }
        $base = $bases[0];
        $heads[$base->firstArticle()] = $this->fetch($base->firstArticle(), true, $budget, $deadline, $persist);
        SourceEnvelope::validate($heads[$base->firstArticle()], $base);
        $metadata[$base->firstArticle()] = $this->payload($base, $budget, $deadline, $persist)['data'];
        $manifest = (new Par2Inventory)->parse($metadata[$base->firstArticle()]);
        foreach ($files as $file) {
            $names[$file->filename][] = $file;
            $id = $file->firstArticle();
            $heads[$id] ??= $this->fetch($id, true, $budget, $deadline, $persist);
            if ($file->isPar2() && ! isset($metadata[$id])) {
                try {
                    $metadata[$id] = $this->payload($file, $budget, $deadline, $persist)['data'];
                } catch (UnexpectedValueException) {
                    $metadata[$id] = '';
                }
            }
        }
        $probed = 0;
        foreach ($names as $name => $candidates) {
            if (count($candidates) < 2 || ! isset($manifest->files[$name])) {
                continue;
            }
            foreach ($candidates as $file) {
                if ($probed >= 4) {
                    break 2;
                }
                $probed++;
                try {
                    $payload = $this->payload($file, $budget, $deadline, $persist);
                    if (strlen($payload['data']) < min(16384, $payload['length'])) {
                        continue;
                    }
                    $probe = ['length' => $payload['length'], 'prefix' => md5(substr($payload['data'], 0, 16384))];
                    if (strlen($payload['data']) === $payload['length']) {
                        $probe['full'] = md5($payload['data']);
                    }
                    $probes[$file->firstArticle()] = $probe;
                } catch (UnexpectedValueException) {
                    // A corrupt/short probe does not eliminate a competing candidate.
                }
            }
        }

        return (new PostingResolver)->resolve($files, $heads, $metadata, $probes);
    }

    private function fetch(string $id, bool $head, TrafficBudget $budget, float $deadline, bool $persist): string
    {
        $key = hash('sha256', self::VERSION.($head ? ':HEAD:' : ':BODY:').$id);
        if (isset($this->memory[$key]) && $this->memory[$key]['expires'] > now()->timestamp) {
            return $this->memory[$key]['data'];
        }
        $cached = DB::table('reconciliation_evidence')->where('key', $key)->where('expires_at', '>', now())->first(['response', 'expires_at']);
        if ($cached !== null) {
            Log::debug('Collection reconciliation evidence cache hit', ['key' => $key]);

            $data = (string) base64_decode($cached->response, true);
            $this->memory[$key] = ['data' => $data, 'expires' => strtotime($cached->expires_at)];

            return $data;
        }
        $result = $this->pool->fetchBoundedArticle($id, $head,
            (int) config('collection-reconciliation.'.($head ? 'head_bytes' : 'body_bytes')),
            min($deadline, microtime(true) + (int) config('collection-reconciliation.request_seconds')), $budget);
        if ($result->data === null) {
            throw new RuntimeException($result->reason);
        }
        if ($persist) {
            DB::table('reconciliation_evidence')->upsert([
                'key' => $key, 'response' => base64_encode($result->data),
                'expires_at' => now()->addSeconds((int) config('collection-reconciliation.cache_seconds')),
            ], ['key'], ['response', 'expires_at']);
        }

        $this->memory[$key] = ['data' => $result->data, 'expires' => now()->timestamp + (int) config('collection-reconciliation.cache_seconds')];

        return $result->data;
    }

    /** @return array{data: string, length: int} */
    private function payload(PostingFile $file, TrafficBudget $budget, float $deadline, bool $persist): array
    {
        $raw = $this->fetch($file->firstArticle(), false, $budget, $deadline, $persist);
        $header = $this->decoder->extractMetadata($raw);
        if ($header === null || $header['name'] !== $file->filename || $header['size'] === null || $header['size'] < 0
            || ! preg_match('/^=yend\b[^\r\n]*\bsize=(\d+)/im', $raw, $end)) {
            throw new UnexpectedValueException('invalid_yenc');
        }
        $multipart = preg_match('/^=ypart\b[^\r\n]*\bbegin=(\d+)\s+end=(\d+)/im', $raw, $part) === 1;
        $decoded = $this->decoder->decodeWithCrcStatus($raw);
        if ($decoded->crcFailed || strlen($decoded->data) !== (int) $end[1]
            || ($multipart && ((int) $part[1] !== 1 || (int) $part[2] !== strlen($decoded->data)))
            || (! $multipart && strlen($decoded->data) !== $header['size'])
            || strlen($decoded->data) > $header['size']
            || ($file->isPar2() && strlen($decoded->data) !== $header['size'])) {
            throw new UnexpectedValueException('contradictory_yenc');
        }

        return ['data' => $decoded->data, 'length' => $header['size']];
    }
}
