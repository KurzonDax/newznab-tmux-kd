<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\Par2Inventory;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reconciliation\Par2Fixture;

class ReconciliationPar2InventoryTest extends TestCase
{
    public function test_validates_the_course_manifest_without_recovery_slices(): void
    {
        $manifest = (new Par2Inventory)->parse(Par2Fixture::metadata(Par2Fixture::course()));
        $this->assertCount(22, $manifest->files);
        $this->assertSame(64, $manifest->files['001-lesson-a.mkv']['size']);
        $this->assertSame(md5(str_repeat(chr(1), 64)), $manifest->files['001-lesson-a.mkv']['prefix']);
    }

    public function test_corrupt_truncated_and_incomplete_metadata_is_rejected(): void
    {
        $valid = Par2Fixture::metadata(Par2Fixture::course());
        $broken = $valid;
        $broken[80] = chr(ord($broken[80]) ^ 1);
        foreach (['', substr($valid, 0, -1), substr($valid, 0, 64), $broken, substr($valid, 428)] as $bytes) {
            try {
                (new Par2Inventory)->parse($bytes);
                $this->fail('Invalid PAR2 metadata was accepted.');
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_forged_file_identity_is_rejected_even_with_a_valid_packet_checksum(): void
    {
        $data = Par2Fixture::metadata(['example.mkv' => 'payload']);
        $offset = unpack('Pvalue', substr($data, 8, 8))['value'];
        $data[$offset + 64] = chr(ord($data[$offset + 64]) ^ 1);
        $length = unpack('Pvalue', substr($data, $offset + 8, 8))['value'];
        $data = substr_replace($data, md5(substr($data, $offset + 32, $length - 32), true), $offset + 16, 16);
        $this->expectExceptionMessage('par2_file_identity');
        (new Par2Inventory)->parse($data);
    }

    public function test_parser_limits_are_enforced_before_accepting_inventory(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new Par2Inventory)->parse(Par2Fixture::metadata(Par2Fixture::course()), maxFiles: 21);
    }
}
