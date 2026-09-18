<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryPaneText;
use PHPUnit\Framework\TestCase;

final class RecoveryPaneTextTest extends TestCase
{
    public function test_tokens_are_readable_and_severity_is_preserved(): void
    {
        $text = new RecoveryPaneText;
        self::assertSame('already covered, nothing to download', $text->phrase('reused_capture'));
        self::assertSame('downloaded, but saving the headers failed; retrying', $text->phrase('capture_handoff_pending'));
        self::assertSame('ready, queued for publication', $text->phrase('ready'));
        self::assertSame('some new token', $text->phrase('some_new_token'));
        self::assertSame('error', $text->level('worker_failed'));
        self::assertSame('warning', $text->level('retry_pending'));
        self::assertSame('primary', $text->level('captured'));
    }

    public function test_pass_and_worker_summaries_are_sentences(): void
    {
        $text = new RecoveryPaneText;
        self::assertSame('Housekeeping: nothing to do', $text->housekeepingLine(['expired_headers' => 0]));
        self::assertSame('Housekeeping: expired 1,000 headers, compacted 250 incomplete scans', $text->housekeepingLine(['expired_headers' => 1000, 'compacted_incomplete_scans' => 250]));
        self::assertSame('Housekeeping: 2 new counter', $text->housekeepingLine(['new_counter' => 2]));
        self::assertSame('Planning: nothing to do', $text->planningLine(['gaps_queued' => 0, 'runs_refreshed' => 0, 'bundles_refreshed' => 0, 'frontier' => []]));
        self::assertSame('Planning: 12 gap downloads queued, 3 runs refreshed, 2 candidates refreshed, frontier: 1 frontier evidence rebuilt', $text->planningLine(['gaps_queued' => 12, 'runs_refreshed' => 3, 'bundles_refreshed' => 2, 'frontier' => ['frontier_rebuilt' => 1]]));
        self::assertSame('gap alt.x 1,001-2,000 (1,000 articles): captured (2.9 s)', $text->workerLine(['purpose' => 'gap', 'group' => 'alt.x', 'first' => 1001, 'last' => 2000, 'bundle_id' => 2, 'outcome' => 'captured', 'seconds' => 2.94]));
        self::assertSame('index for candidate 2: downloaded (0.8 s)', $text->workerLine(['purpose' => 'index', 'bundle_id' => 2, 'outcome' => 'downloaded', 'seconds' => 0.8]));
        self::assertSame('idle', $text->workerLine(['bundle_id' => null, 'outcome' => 'idle']));
    }
}
