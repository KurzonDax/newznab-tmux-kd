<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2Sidecar\SidecarDescriptorInventory;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reconciliation\Par2Fixture;

class SidecarDescriptorInventoryTest extends TestCase
{
    public function test_main_and_descriptors_prove_complete_membership_without_a_filename_cap(): void
    {
        $inventory = (new SidecarDescriptorInventory)->parse(Par2Fixture::metadata(Par2Fixture::course()));
        self::assertTrue($inventory['complete']);
        self::assertCount(22, $inventory['descriptors']);
        self::assertCount(22, $inventory['sets'][array_key_first($inventory['sets'])]);
    }

    public function test_missing_main_and_missing_descriptors_never_prove_complete_membership(): void
    {
        $bytes = Par2Fixture::metadata(['Movie.mkv' => str_repeat('A', 20000)]);
        $mainLength = unpack('Plength', substr($bytes, 8, 8))['length'];
        foreach ([substr($bytes, $mainLength), substr($bytes, 0, $mainLength)] as $incomplete) {
            self::assertFalse((new SidecarDescriptorInventory)->parse($incomplete)['complete']);
        }
    }

    public function test_identical_prefix_and_size_do_not_verify_unseen_tail_and_conflicting_full_hashes_are_retained(): void
    {
        $first = Par2Fixture::metadata(['Movie.mkv' => str_repeat('A', 16384).str_repeat('B', 3616)]);
        $second = Par2Fixture::metadata(['Movie.mkv' => str_repeat('A', 16384).str_repeat('C', 3616)]);
        $inventory = (new SidecarDescriptorInventory)->parse($first.$second);
        self::assertFalse($inventory['complete']);
        self::assertSame('conflicting_descriptor', $inventory['reason']);
        self::assertTrue(SidecarDescriptorInventory::ambiguous($inventory));
        self::assertCount(2, $inventory['descriptors']);
        self::assertSame($inventory['descriptors'][0]['hash16k'], $inventory['descriptors'][1]['hash16k']);
        self::assertNotSame($inventory['descriptors'][0]['full_hash'], $inventory['descriptors'][1]['full_hash']);
    }

    public function test_overflow_cannot_be_mistaken_for_an_unambiguous_inventory(): void
    {
        $bytes = '';
        for ($index = 0; $index < 1026; $index++) {
            $bytes .= Par2Fixture::metadata(['Movie'.$index.'.mkv' => 'payload']);
        }
        $inventory = (new SidecarDescriptorInventory)->parse($bytes);
        self::assertFalse($inventory['complete']);
        self::assertSame('descriptor_overflow', $inventory['reason']);
        self::assertTrue(SidecarDescriptorInventory::ambiguous($inventory));
        self::assertCount(1025, $inventory['descriptors']);
    }

    public function test_a_truncated_recovery_slice_keeps_complete_already_fetched_metadata(): void
    {
        $metadata = Par2Fixture::metadata(['Movie.mkv' => str_repeat('A', 20000)]);
        $slice = Par2Fixture::packet(substr($metadata, 32, 16), "PAR 2.0\0RecvSlic", str_repeat('R', 1000));
        self::assertTrue((new SidecarDescriptorInventory)->parse($metadata.substr($slice, 0, 100))['complete']);
    }
}
