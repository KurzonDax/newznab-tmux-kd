<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ObfuscationRecovery\RecoveryWire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
use Tests\TestCase;

final class RecoveryOverviewTransportTest extends TestCase
{
    use InteractsWithRecoveryNntpServer;

    protected function tearDown(): void
    {
        $this->stopRecoveryServers();
        parent::tearDown();
    }

    #[DataProvider('transports')]
    public function test_bounded_primary_overview_preserves_raw_fields_and_accounts_closed_transport(bool $tls): void
    {
        $response = "224 overview\r\n4000000001\topaque\tfixture\tTue, 01 Jan 2030 00:00:00 +0000\t<raw@fixture>\t\t1500\t10\tXref: fixture alt.binaries.fixture:4000000001\r\n.\r\n";
        $provider = $this->server('', tls: $tls, dialogue: ["GROUP alt.binaries.fixture\r\n" => "211 1 4000000001 4000000001 alt.binaries.fixture\r\n",
            "XOVER 4000000001-4000000001\r\n" => $response]);
        $result = (new RecoveryWire(caFile: $this->certificateAuthority))->overview($provider, 'alt.binaries.fixture', 4000000001, 4000000001);
        $this->assertSame('success', $result->transport->outcome);
        $this->assertTrue($result->transport->closed);
        $this->assertSame(1, $result->transport->connectionsOpened);
        $this->assertGreaterThan(strlen($response), $result->transport->plaintextReceived);
        $this->assertSame(4000000001, $result->headers[0]['Number']);
        $this->assertSame('<raw@fixture>', $result->headers[0]['Message-ID']);
        $this->assertSame('opaque', $result->headers[0]['Subject']);
        $this->assertSame('1500', $result->headers[0]['Bytes']);
    }

    public static function transports(): array
    {
        return [[false], [true]];
    }

    public function test_an_out_of_range_overview_cannot_supply_partial_positive_coverage(): void
    {
        $provider = $this->server('', dialogue: ["GROUP alt.binaries.fixture\r\n" => "211 1 1 2 alt.binaries.fixture\r\n",
            "XOVER 1-1\r\n" => "224 overview\r\n2\topaque\tfixture\tTue, 01 Jan 2030 00:00:00 +0000\t<raw@fixture>\t\t1500\t10\r\n.\r\n"]);
        $result = (new RecoveryWire)->overview($provider, 'alt.binaries.fixture', 1, 1);
        $this->assertSame('semantic_failure', $result->transport->outcome);
        $this->assertSame([], $result->headers);
        $this->assertTrue($result->transport->closed);
    }
}
