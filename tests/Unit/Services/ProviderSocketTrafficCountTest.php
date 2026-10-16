<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\BoundedNntpStream;
use App\Services\NNTP\NntpTrafficMeter;
use App\Services\ObfuscationRecovery\RecoveryWire;
use Illuminate\Support\Facades\DB;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class ProviderSocketTrafficCountTest extends TestCase
{
    use InteractsWithRecoveryNntpServer;

    private const string ARTICLE = "222 0 <fixture@local> body\r\n=ybegin line=128 size=1 name=index.par2\r\na\r\n=yend size=1\r\n.\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('nntp_provider_traffic');
    }

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        parent::tearDown();
    }

    public function test_a_bounded_fetch_records_exactly_the_bytes_it_reports(): void
    {
        $complete = (new BoundedNntpStream)->fetch($this->server(self::ARTICLE, position: 1),
            '<fixture@local>', false, 4096, microtime(true) + 5);
        $limited = (new BoundedNntpStream)->fetch($this->server(self::ARTICLE.str_repeat('x', 200), position: 2),
            '<fixture@local>', false, 40, microtime(true) + 5);

        $this->assertSame('ok', $complete->reason);
        $this->assertSame('response_limit', $limited->reason);
        $this->assertSame([
            'fixture-1' => $complete->bytes,
            'fixture-2' => $limited->bytes,
        ], $this->recorded());
    }

    public function test_a_recovery_fetch_records_exactly_its_plaintext_received_over_tcp_and_tls(): void
    {
        $plain = (new RecoveryWire)->fetch($this->server(self::ARTICLE, position: 1),
            'fixture@local', 1048576, false, 2097152, 32768);
        $tls = $this->server(self::ARTICLE, tls: true, position: 2);
        $encrypted = (new RecoveryWire(caFile: $this->certificateAuthority))->fetch($tls,
            'fixture@local', 1048576, false, 2097152, 32768);

        $this->assertSame('success', $plain->outcome);
        $this->assertSame('success', $encrypted->outcome);
        $this->assertSame([
            'fixture-1' => $plain->plaintextReceived,
            'fixture-2' => $encrypted->plaintextReceived,
        ], $this->recorded());
    }

    /** @return array<string, int> */
    private function recorded(): array
    {
        app(NntpTrafficMeter::class)->flush();

        return DB::table('nntp_provider_traffic')->orderBy('provider')->pluck('bytes_received', 'provider')
            ->map(fn (mixed $bytes): int => (int) $bytes)->all();
    }
}
