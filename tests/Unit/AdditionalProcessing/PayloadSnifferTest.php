<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\Enums\PayloadClassification;
use App\Services\AdditionalProcessing\PayloadSniffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PayloadSnifferTest extends TestCase
{
    /**
     * @return iterable<string, array{string, PayloadClassification}>
     */
    public static function magicBytes(): iterable
    {
        yield 'rar4' => ["Rar!\x1A\x07\x00payload", PayloadClassification::Rar];
        yield 'rar5' => ["Rar!\x1A\x07\x01\x00payload", PayloadClassification::Rar];
        yield 'zip' => ["PK\x03\x04payload", PayloadClassification::Zip];
        yield 'par2' => ["PAR2\x00PKTpayload", PayloadClassification::Par2];
        yield 'matroska' => ["\x1A\x45\xDF\xA3payload", PayloadClassification::Matroska];
        yield 'mp4' => ["\x00\x00\x00\x18ftypisom", PayloadClassification::Mp4];
        yield 'avi' => ["RIFF\x00\x00\x00\x00AVI payload", PayloadClassification::Avi];
        yield 'text' => ["Release information\r\nCodec: AV1", PayloadClassification::Text];
        yield 'unknown' => ["\x00\x01\x02\x03payload", PayloadClassification::Unknown];
    }

    #[Test]
    #[DataProvider('magicBytes')]
    public function it_classifies_payload_magic(string $payload, PayloadClassification $expected): void
    {
        $this->assertSame($expected, (new PayloadSniffer)->classify($payload)->classification);
    }

    #[Test]
    public function it_reads_first_volume_markers_from_rar4_and_rar5_main_headers(): void
    {
        $rar4First = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0101).'payload';
        $rar4Later = "Rar!\x1A\x07\x00\x00\x00\x73".pack('v', 0x0001).'payload';
        $rar5First = "Rar!\x1A\x07\x01\x00"."\x00\x00\x00\x00"."\x04\x01\x00\x03\x00";
        $rar5Later = "Rar!\x1A\x07\x01\x00"."\x00\x00\x00\x00"."\x04\x01\x00\x03\x01";

        $sniffer = new PayloadSniffer;

        $this->assertTrue($sniffer->classify($rar4First)->likelyFirstVolume);
        $this->assertFalse($sniffer->classify($rar4Later)->likelyFirstVolume);
        $this->assertTrue($sniffer->classify($rar5First)->likelyFirstVolume);
        $this->assertFalse($sniffer->classify($rar5Later)->likelyFirstVolume);
    }
}
