<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryFormats;
use App\Services\ObfuscationRecovery\RecoveryRarHeaders;
use PHPUnit\Framework\TestCase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;

final class RecoveryFormatsTest extends TestCase
{
    public function test_container_headers_distinguish_supported_and_conflicting_formats(): void
    {
        $formats = new RecoveryFormats;
        $this->assertSame('mkv', $formats->detect("\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska"));
        $this->assertSame('webm', $formats->detect("\x1a\x45\xdf\xa3\x87\x42\x82\x84webm"));
        $this->assertSame('mp4', $formats->detect(pack('N', 20).'ftypisom'.pack('N', 0).'mp42'));
        $this->assertSame('mov', $formats->detect(pack('N', 16).'ftypqt  '.pack('N', 0)));
        $this->assertSame('executable', $formats->detect('MZ'.str_repeat('x', 100)));
        $this->assertSame('rar5', $formats->detect("Rar!\x1a\x07\x01\0"));
        $this->assertNull($formats->detect("\x1a\x45\xdf\xa3\xff\x42\x82\x88matroska"));
    }

    public function test_rar_headers_validate_checksums_and_keep_contained_evidence_scoped(): void
    {
        $fixture = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800]);
        $reader = new RecoveryRarHeaders;
        foreach ($fixture['volumes'] as $index => $volume) {
            $header = $reader->inspect(substr($volume, 0, 16384));
            $this->assertSame($index === 0, $header['first_volume']);
            $this->assertSame('fixture.bin', $header['contained_name']);
            $this->assertFalse($header['encrypted']);
            $this->assertSame($index !== 0, $header['split_before']);
        }
        $corrupt = substr($fixture['volumes'][0], 0, 16384);
        $corrupt[10] = chr(ord($corrupt[10]) ^ 1);
        $this->expectExceptionMessage('rar_header_checksum');
        $reader->inspect($corrupt);
    }
}
