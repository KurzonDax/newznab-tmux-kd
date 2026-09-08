<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryPar2;
use PHPUnit\Framework\TestCase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;

final class RecoveryPar2Test extends TestCase
{
    public function test_complete_rar_fixture_retains_every_description_and_slice_inventory(): void
    {
        $fixture = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800]);
        $this->assertSame('c9c88fab31c37d1a0a05fbbd429a53cc', md5($fixture['content']));
        $this->assertSame(5417320, strlen($fixture['content']));
        $files = [];
        foreach ($fixture['volumes'] as $index => $volume) {
            $files[sprintf('fixture.part%02d.rar', $index + 1)] = $volume;
        }
        $bytes = SyntheticPosting::par2($files);
        $this->assertSame(1160, strlen($bytes));
        $inventory = (new RecoveryPar2)->parse($bytes);
        $this->assertCount(4, $inventory->files);
        $this->assertSame(1048576, $inventory->sliceSize);
        foreach ($inventory->files as $file) {
            $this->assertSame(strlen($files[$file->filename]), $file->size);
            $this->assertSame(md5($files[$file->filename], true), $file->md5);
            $this->assertSame(md5(substr($files[$file->filename], 0, 16384), true), $file->prefixMd5);
            $this->assertCount((int) ceil($file->size / 1048576), $file->sliceChecks);
        }
    }

    public function test_packet_hash_corruption_is_rejected(): void
    {
        $bytes = SyntheticPosting::par2(['a.mkv' => 'hello']);
        $bytes[80] = chr(ord($bytes[80]) ^ 1);
        $this->expectExceptionMessage('par2_packet_checksum');
        (new RecoveryPar2)->parse($bytes);
    }

    public function test_identical_packets_are_idempotent_but_incomplete_inventory_is_rejected(): void
    {
        $bytes = SyntheticPosting::par2(['abc.mkv' => 'hello', 'abcd.mp4' => 'world']);
        $firstLength = unpack('P', substr($bytes, 8, 8))[1];
        $this->assertCount(2, (new RecoveryPar2)->parse($bytes.substr($bytes, 0, $firstLength))->files);
        $this->expectExceptionMessage('incomplete_par2_inventory');
        (new RecoveryPar2)->parse(substr($bytes, 0, $firstLength));
    }

    public function test_unpadded_filename_drives_file_identity_and_large_inventories_are_not_truncated(): void
    {
        $files = [];
        for ($index = 0; $index < 32; $index++) {
            $files['fixture-'.$index.'.mkv'] = 'payload-'.$index;
        }
        $parsed = (new RecoveryPar2)->parse(SyntheticPosting::par2($files));
        $this->assertCount(32, $parsed->files);
        foreach ($parsed->files as $file) {
            $this->assertSame(md5($file->prefixMd5.pack('P', $file->size).$file->filename, true), $file->id);
        }
        $files['extra.mkv'] = 'extra';
        $this->expectExceptionMessage('unsupported_par2_inventory_count');
        (new RecoveryPar2)->parse(SyntheticPosting::par2($files));
    }
}
