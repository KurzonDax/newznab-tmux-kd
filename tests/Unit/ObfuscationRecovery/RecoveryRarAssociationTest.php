<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryInventory;
use App\Services\ObfuscationRecovery\RecoveryPar2;
use App\Services\ObfuscationRecovery\RecoveryRarAssociation;
use PHPUnit\Framework\TestCase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;

final class RecoveryRarAssociationTest extends TestCase
{
    public function test_every_volume_requires_both_a_matching_start_and_its_terminal_declaration(): void
    {
        [$inventory, $volumes, $blocks] = $this->fixture();
        $service = new RecoveryRarAssociation;
        $files = $service->preflight($inventory, 3, 3, 11, 'index@local', $blocks);
        $evidence = [];
        foreach ($volumes as $i => $volume) {
            $size = strlen($volume);
            $total = (int) ceil($size / 716800);
            $evidence['start'.$i.'@local'] = new RecoveryArticle('opaque', $size, 1, $total, 1, 716800, substr($volume, 0, 16384), false, false, false, null);
            $evidence['end'.$i.'@local'] = new RecoveryArticle('opaque', $size, $total, $total, ($total - 1) * 716800 + 1, $size, '', false, false, false, null);
        }
        $verified = $service->verify($files, $evidence);
        $this->assertCount(4, $verified);
        $this->assertSame([1, 2, 3, 4], array_column($verified, 'archive_ordinal'));
        $this->assertTrue($verified[0]['archive_header']['first_volume']);
        $this->assertFalse($verified[3]['archive_header']['first_volume']);
        unset($evidence['end3@local']);
        $this->expectExceptionMessage('incomplete_terminal_evidence');
        $service->verify($files, $evidence);
    }

    public function test_terminal_ties_are_rejected_before_any_body_work(): void
    {
        [$inventory, , $blocks] = $this->fixture();
        $blocks[1]['terminal_count'] = 2;
        $this->expectExceptionMessage('ambiguous_rar_terminal');
        (new RecoveryRarAssociation)->preflight($inventory, 3, 3, 11, 'index@local', $blocks);
    }

    public function test_crc_valid_encrypted_volume_with_matching_par2_hashes_is_rejected(): void
    {
        [$inventory, $volumes, $blocks] = $this->fixture(true);
        $service = new RecoveryRarAssociation;
        $files = $service->preflight($inventory, 3, 3, 11, 'index@local', $blocks);
        $size = strlen($volumes[0]);
        $evidence = ['start0@local' => new RecoveryArticle('opaque', $size, 1, 3, 1, 716800, substr($volumes[0], 0, 16384), false, false, false, null),
            'end0@local' => new RecoveryArticle('opaque', $size, 3, 3, 1433601, $size, '', false, false, false, null)];
        $this->expectExceptionMessage('rar_encryption_unsupported');
        $service->verify($files, $evidence);
    }

    public function test_a_wrong_terminal_range_is_not_repaired_by_sorting_yenc_parts(): void
    {
        [$inventory, $volumes, $blocks] = $this->fixture();
        $service = new RecoveryRarAssociation;
        $files = $service->preflight($inventory, 3, 3, 11, 'index@local', $blocks);
        $size = strlen($volumes[0]);
        $evidence = ['start0@local' => new RecoveryArticle('opaque', $size, 1, 3, 1, 716800, substr($volumes[0], 0, 16384), false, false, false, null),
            'end0@local' => new RecoveryArticle('opaque', $size, 2, 3, 716801, 1433600, '', false, false, false, null)];
        $this->expectExceptionMessage('rar_terminal_declaration_conflict');
        $service->verify($files, $evidence);
    }

    /** @return array{RecoveryInventory,list<string>,list<array<string,mixed>>} */
    private function fixture(bool $encrypted = false): array
    {
        $volumes = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800])['volumes'];
        if ($encrypted) {
            $volume = $volumes[0];
            $offset = 20;
            $length = unpack('v', substr($volume, $offset + 5, 2))[1];
            $header = substr($volume, $offset + 2, $length - 2);
            $flags = unpack('v', substr($header, 1, 2))[1] | 4;
            $header = substr_replace($header, pack('v', $flags), 1, 2);
            $volumes[0] = substr_replace($volume, pack('v', crc32($header) & 0xFFFF).$header, $offset, $length);
        }
        $named = $blocks = [];
        $start = 1;
        foreach ($volumes as $i => $volume) {
            $named[sprintf('Archive.part%02d.rar', $i + 1)] = $volume;
            $count = (int) ceil(strlen($volume) / 716800);
            $blocks[] = ['start_ordinal' => $start, 'end_ordinal' => $start + $count - 1, 'observed_count' => $count,
                'start_ms' => 100 + $start, 'earliest' => [['message_id' => 'start'.$i.'@local', 'embedded_timestamp_ms' => 100 + $start]],
                'terminal_count' => 1, 'terminal_message_id' => 'end'.$i.'@local'];
            $start += $count;
        }

        return [(new RecoveryPar2)->parse(SyntheticPosting::par2($named)), $volumes, $blocks];
    }
}
