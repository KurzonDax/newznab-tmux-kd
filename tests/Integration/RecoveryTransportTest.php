<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ObfuscationRecovery\RecoveryWire;
use PHPUnit\Framework\TestCase;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;

final class RecoveryTransportTest extends TestCase
{
    use InteractsWithRecoveryNntpServer;

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        parent::tearDown();
    }

    public function test_a_stat_status_cannot_be_accepted_as_a_body_response(): void
    {
        $provider = $this->server("223 0 <fixture@local> article exists\r\n");
        $result = (new RecoveryWire)->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('unexpected_body_response', $result->reason);
        $this->assertNull($result->article);
        $this->assertTrue($result->closed);
    }

    public function test_real_socket_full_article_checks_identity_framing_and_counters(): void
    {
        $body = "=ybegin line=128 size=4 name=index.par2\r\naaaa\r\n=yend size=4 crc32=".hash('crc32b', '7777')."\r\n.\r\n";
        $provider = $this->server("222 0 <fixture@local> body\r\n".$body);
        $result = (new RecoveryWire)->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('success', $result->outcome);
        $this->assertSame('7777', $result->article->data);
        $this->assertTrue($result->article->complete);
        $this->assertTrue($result->closed);
        $this->assertGreaterThan(strlen($body), $result->plaintextReceived);
        $this->assertGreaterThan(0, $result->plaintextSent);
        $this->assertSame(1, $result->connectionsOpened);
        $this->assertNull($result->encryptedReceived);
        $this->assertGreaterThan(0, $result->receiveWindow);
    }

    public function test_prefix_aborts_and_closes_without_draining_the_article(): void
    {
        $body = "=ybegin part=1 total=4 line=128 size=2867200 name=file.mkv\r\n=ypart begin=1 end=716800\r\n";
        $body .= str_repeat(str_repeat('a', 128)."\r\n", 5600)."=yend size=716800 part=1\r\n.\r\n";
        $result = (new RecoveryWire)->fetch($this->server("222 0 <fixture@local> body\r\n".$body), 'fixture@local', 16384, true, 131072, 32768);
        $this->assertSame('success', $result->outcome);
        $this->assertSame(16384, strlen($result->article->data));
        $this->assertFalse($result->article->complete);
        $this->assertTrue($result->closed);
        $this->assertLessThan(98304, $result->plaintextReceived + $result->plaintextSent);
    }

    public function test_wrong_id_and_truncated_body_are_distinct_failures(): void
    {
        $wrong = (new RecoveryWire)->fetch($this->server("222 0 <other@local> body\r\n.\r\n"), 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('semantic_failure', $wrong->outcome);
        $this->assertSame('response_identity_mismatch', $wrong->reason);
        $truncated = (new RecoveryWire)->fetch($this->server("222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=x.par2\r\naaaa\r\n"), 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('transport_failure', $truncated->outcome);
        $this->assertNull($truncated->article);
        $this->assertTrue($truncated->closed);
    }

    public function test_slow_drip_cannot_extend_the_whole_request_deadline(): void
    {
        $provider = $this->server("222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=x.par2\r\naaaa\r\n", drip: true);
        $start = microtime(true);
        $result = (new RecoveryWire(connectTimeout: 1, readTimeout: 0.1, requestTimeout: 0.25))
            ->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('transport_failure', $result->outcome);
        $this->assertLessThan(0.7, microtime(true) - $start);
        $this->assertTrue($result->closed);
    }

    public function test_tls_uses_certificate_verification_and_reports_unmeasured_encrypted_bytes(): void
    {
        $body = "222 0 <fixture@local> body\r\n=ybegin line=128 size=1 name=index.par2\r\na\r\n=yend size=1\r\n.\r\n";
        $provider = $this->server($body, tls: true);
        $result = (new RecoveryWire(caFile: $this->certificateAuthority))->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('success', $result->outcome);
        $this->assertSame('7', $result->article->data);
        $this->assertNull($result->encryptedReceived);
        $this->assertTrue($result->closed);
        $untrusted = (new RecoveryWire)->fetch($this->server($body, tls: true), 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('provider_tls_failed', $untrusted->reason);
        $this->assertTrue($untrusted->closed);
    }

    public function test_dot_stuffing_is_decoded_once_and_an_adapter_cannot_be_reused(): void
    {
        $body = "222 0 <fixture@local> body\r\n=ybegin line=128 size=4 name=index.par2\r\n..aaa\r\n=yend size=4\r\n.\r\n";
        $wire = new RecoveryWire;
        $provider = $this->server($body);
        $result = $wire->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
        $this->assertSame('success', $result->outcome);
        $this->assertSame(chr(4).'777', $result->article->data);
        $this->expectExceptionMessage('invalid_transport_allowance');
        $wire->fetch($provider, 'fixture@local', 1048576, false, 2097152, 32768);
    }
}
