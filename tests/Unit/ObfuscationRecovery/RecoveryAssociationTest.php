<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryAssociation;
use App\Services\ObfuscationRecovery\RecoveryInventory;
use App\Services\ObfuscationRecovery\RecoveryProtectedFile;
use PHPUnit\Framework\TestCase;

final class RecoveryAssociationTest extends TestCase
{
    public function test_preflight_checks_every_file_before_requesting_any_anchor_headers(): void
    {
        $file = $this->file();
        $inventory = new RecoveryInventory(str_repeat('s', 16), 1048576, [$file]);
        $runs = [$this->candidateRun(1, 1, 1), $this->candidateRun(2, 3, 3), $this->candidateRun(3, 3, 2)];
        $this->expectExceptionMessage('ambiguous_media_membership');
        (new RecoveryAssociation)->mediaPreflight($inventory, $runs, function (): array {
            $this->fail('Ambiguous inventory must fail before anchor planning.');
        });
    }

    public function test_preflight_preserves_all_bounded_tied_targets_and_ignores_unrelated_auxiliary_totals(): void
    {
        $inventory = new RecoveryInventory(str_repeat('s', 16), 1048576, [$this->file()]);
        $files = (new RecoveryAssociation)->mediaPreflight($inventory,
            [$this->candidateRun(1, 1, 1), $this->candidateRun(2, 3, 3), $this->candidateRun(3, 9, 2)],
            static fn (): array => [['message_id' => 'b@local', 'embedded_timestamp_ms' => 100], ['message_id' => 'a@local', 'embedded_timestamp_ms' => 100]]);
        $this->assertSame(['a@local', 'b@local'], $files[0]['targets']);
        $this->assertSame(3, $files[0]['expected_total']);
    }

    public function test_a_tied_part_two_before_part_one_is_supported_without_reordering_membership(): void
    {
        $file = $this->file();
        $partTwo = new RecoveryArticle('opaque', $file->size, 2, 3, 716801, 1433600, str_repeat('x', 16384), false, false, false, null);
        $partOne = new RecoveryArticle('opaque', $file->size, 1, 3, 1, 716800, $this->prefix(), false, false, false, null);
        $result = (new RecoveryAssociation)->anchor($file, ['a@local', 'b@local'], ['a@local' => $partTwo, 'b@local' => $partOne], 'mkv');
        $this->assertSame('b@local', $result['message_id']);
        $this->assertFalse($result['small_file_verified']);
        $this->expectExceptionMessage('multiple_matching_anchors');
        (new RecoveryAssociation)->anchor($file, ['a@local', 'b@local'], ['a@local' => $partOne, 'b@local' => $partOne], 'mkv');
    }

    public function test_declared_media_format_does_not_require_conclusive_magic_but_incompatible_magic_blocks(): void
    {
        foreach ([str_repeat('x', 16384), 'MZ'.str_repeat('x', 16382)] as $prefix) {
            $file = new RecoveryProtectedFile(str_repeat('f', 16), 'file.mp4', 1433700, str_repeat('m', 16), md5($prefix, true), []);
            $article = new RecoveryArticle('opaque', $file->size, 1, 3, 1, 716800, $prefix, false, false, false, null);
            if (str_starts_with($prefix, 'MZ')) {
                $this->expectExceptionMessage('unsupported_or_conflicting_format');
            }
            $anchor = (new RecoveryAssociation)->anchor($file, ['a@local'], ['a@local' => $article], 'mp4');
            $this->assertSame('mp4', $anchor['format']);
            $this->assertNull($anchor['observed_format']);
        }
    }

    private function file(): RecoveryProtectedFile
    {
        return new RecoveryProtectedFile(str_repeat('f', 16), 'file.mkv', 1433700, str_repeat('m', 16), md5($this->prefix(), true), []);
    }

    private function prefix(): string
    {
        return str_pad("\x1a\x45\xdf\xa3\x8b\x42\x82\x88matroska", 16384, 'x');
    }

    /** @return array<string,mixed> */
    private function candidateRun(int $id, int $total, int $count): array
    {
        return ['first_id' => $id, 'first_message_id' => 'm'.$id.'@local', 'start_ms' => 100,
            'advertised_total' => $total, 'observed_count' => $count, 'state' => $count === $total ? 'count_exact' : 'short'];
    }
}
