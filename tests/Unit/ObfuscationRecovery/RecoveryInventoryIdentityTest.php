<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryInventoryIdentity;
use PHPUnit\Framework\TestCase;

final class RecoveryInventoryIdentityTest extends TestCase
{
    public function test_episode_bundle_label_preserves_gaps_and_keeps_file_identity_separate(): void
    {
        $names = [];
        foreach ([1, 2, 3, 4, 6, 7, 8] as $episode) {
            $names[sprintf('%032x', $episode)] = sprintf('Synthetic.Show.S01E%02d.1080p.mkv', $episode);
        }
        $decision = (new RecoveryInventoryIdentity)->media($names);
        $this->assertSame('descriptive_bundle', $decision['outcome']);
        $this->assertSame('Synthetic Show S01 Episodes 01-04,06-08 - 7 files - 1080p', $decision['candidate']);
        $this->assertSame([1, 2, 3, 4, 6, 7, 8], array_column($decision['files'], 'episode'));
        $this->assertFalse($decision['trusted']);
    }

    public function test_inconsistent_identity_stays_unresolved_and_quality_is_never_borrowed_from_one_file(): void
    {
        $identity = new RecoveryInventoryIdentity;
        $this->assertNull($identity->media(['one' => 'First.Show.S01E01.mkv', 'two' => 'Other.Show.S01E02.mkv'])['candidate']);
        $decision = $identity->media(['one' => 'Synthetic.Show.S01E01.1080p.mkv', 'two' => 'Synthetic.Show.S01E02.720p.mkv']);
        $this->assertSame('Synthetic Show S01 Episodes 01-02 - 2 files', $decision['candidate']);
        $this->assertNull($identity->media(['one' => 'Film.One.2026.mkv', 'two' => 'Film.Two.2026.mkv'])['candidate']);
    }

    public function test_duplicate_episode_files_count_as_files_and_archive_volumes_have_one_root(): void
    {
        $identity = new RecoveryInventoryIdentity;
        $decision = $identity->media(['one' => 'Synthetic.Show.S01E01.1080p.mkv', 'two' => 'Synthetic.Show.S01E01.1080p.mp4']);
        $this->assertSame('Synthetic Show S01 Episodes 01 - 2 files - 1080p', $decision['candidate']);
        $this->assertSame('Synthetic.Film.2026', $identity->archive(['Synthetic.Film.2026.part01.rar', 'Synthetic.Film.2026.part02.rar']));
        $this->assertNull($identity->archive(['First.part01.rar', 'Second.part02.rar']));
    }
}
