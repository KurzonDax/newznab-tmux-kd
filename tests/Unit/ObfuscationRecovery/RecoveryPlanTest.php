<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecoveryPlanTest extends TestCase
{
    public function test_plan_preserves_the_complete_inventory_and_separates_planned_from_declared_counts(): void
    {
        $media = new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 2250400, 4, 'feature.mkv', 'mkv');
        $index = new RecoveryFilePlan('Index@example.invalid', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2');
        $plan = new RecoveryPlan(RecoveryAlgorithm::Media, 1, 1, 'group.fixture', 'epoch', str_repeat('b', 32), [$media, $index], str_repeat('c', 64));
        $this->assertSame(2, $plan->plannedFiles());
        $this->assertSame(1, $plan->protectedFiles());
        $this->assertSame(5, $plan->plannedParts());
        $this->assertSame(0, $plan->declaredFiles());
        $this->assertSame('header_ordinal_yenc_offsets', $plan->orderingMode());
        $this->assertSame('protected_media_plus_index', $plan->inventoryScope());
        $this->assertSame($plan->toArray(), RecoveryPlan::fromArray($plan->toArray())->toArray());
    }

    public function test_media_plan_rejects_equal_totals_even_when_sizes_differ(): void
    {
        $files = [
            new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 2250400, 4, 'one.mkv', 'mkv'),
            new RecoveryFilePlan(str_repeat('b', 32), RecoveryFileRole::Media, 2250401, 4, 'two.mp4', 'mp4'),
            new RecoveryFilePlan('Index@example.invalid', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2'),
        ];
        $this->expectException(InvalidArgumentException::class);
        new RecoveryPlan(RecoveryAlgorithm::Media, 1, 1, 'group.fixture', 'epoch', str_repeat('b', 32), $files, str_repeat('c', 64));
    }

    public function test_rar_inventory_counts_volumes_without_claiming_multiple_videos(): void
    {
        $files = [];
        foreach ([1533600, 1533600, 1533600, 816800] as $i => $size) {
            $files[] = new RecoveryFilePlan(str_repeat((string) ($i + 1), 32), RecoveryFileRole::RarVolume, $size, $i === 3 ? 2 : 3, 'fixture.part0'.($i + 1).'.rar', 'rar4');
        }
        $files[] = new RecoveryFilePlan('Index@example.invalid', RecoveryFileRole::Index, 1160, 1, 'recovery.par2', 'par2');
        $plan = new RecoveryPlan(RecoveryAlgorithm::Rar, 1, 1, 'group.fixture', 'epoch', str_repeat('b', 32), $files, str_repeat('c', 64));
        $this->assertSame(12, $plan->plannedParts());
        $this->assertSame(5, $plan->plannedFiles());
        $this->assertSame('protected_rar_volumes_plus_index', $plan->inventoryScope());
        $this->assertFalse($plan->multiMediaInventory());
    }

    public function test_complete_single_article_index_uses_its_own_size_limit(): void
    {
        $index = new RecoveryFilePlan('Index@example.invalid', RecoveryFileRole::Index, 1048576, 1, 'recovery.par2', 'par2');
        $this->assertSame(1, $index->totalParts);
    }

    public function test_malformed_serialization_is_rejected_without_coercing_overflowed_integers(): void
    {
        foreach ([[], ['files' => null], ['files' => [['identity' => 'x']]]] as $data) {
            try {
                RecoveryPlan::fromArray($data);
                $this->fail('Incomplete plans must fail closed.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
