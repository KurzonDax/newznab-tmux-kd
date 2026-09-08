<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryInventoryPolicy;
use App\Services\ObfuscationRecovery\RecoveryPar2;
use PHPUnit\Framework\TestCase;
use Tests\Support\ObfuscationRecovery\SyntheticPosting;

final class RecoveryInventoryPolicyTest extends TestCase
{
    public function test_media_requires_distinct_article_totals_and_case_safe_display_names(): void
    {
        $inventory = (new RecoveryPar2)->parse(SyntheticPosting::par2([
            'A.mkv' => SyntheticPosting::bytes('one', 1433700),
            'a.MP4' => SyntheticPosting::bytes('two', 2150500),
        ]));
        $files = (new RecoveryInventoryPolicy)->media($inventory);
        $this->assertCount(2, $files);
        $this->assertNotSame(strtolower($files[0]['display_name']), strtolower($files[1]['display_name']));
        $totals = array_column($files, 'expected_total');
        sort($totals);
        $this->assertSame([3, 4], $totals);
    }

    public function test_colliding_original_par2_rename_targets_cannot_be_made_safe_by_nzb_labels(): void
    {
        $inventory = (new RecoveryPar2)->parse(SyntheticPosting::par2([
            'A.mkv' => SyntheticPosting::bytes('one', 1433700),
            'a.MKV' => SyntheticPosting::bytes('two', 2150500),
        ]));
        $this->expectExceptionMessage('unsafe_inventory_paths');
        (new RecoveryInventoryPolicy)->media($inventory);
    }

    public function test_different_sizes_with_equal_totals_are_not_separable_media(): void
    {
        $inventory = (new RecoveryPar2)->parse(SyntheticPosting::par2(['one.mkv' => str_repeat('a', 1000), 'two.mp4' => str_repeat('b', 1100)]));
        $this->expectExceptionMessage('unsupported_equal_total_media');
        (new RecoveryInventoryPolicy)->media($inventory);
    }

    public function test_rar_inventory_is_complete_numbered_and_equal_sized_except_for_the_final_volume(): void
    {
        $fixture = SyntheticPosting::rar([1533600, 1533600, 1533600, 816800]);
        $files = [];
        foreach ($fixture['volumes'] as $index => $volume) {
            $files[sprintf('fixture.part%02d.rar', $index + 1)] = $volume;
        }
        $inventory = (new RecoveryPar2)->parse(SyntheticPosting::par2($files));
        $mapped = (new RecoveryInventoryPolicy)->rar($inventory, 3, 3, 11);
        $this->assertSame([3, 3, 3, 2], array_column($mapped, 'expected_total'));
        $this->assertSame([1, 2, 3, 4], array_column($mapped, 'archive_ordinal'));
    }

    public function test_inventory_cannot_hide_a_nonmedia_member(): void
    {
        $inventory = (new RecoveryPar2)->parse(SyntheticPosting::par2(['one.mkv' => 'video', 'setup.exe' => 'binary']));
        $this->expectExceptionMessage('unsupported_media_inventory_member');
        (new RecoveryInventoryPolicy)->media($inventory);
    }
}
