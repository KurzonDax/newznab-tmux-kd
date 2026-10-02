<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\PhantomTrailingFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhantomTrailingSets;

/**
 * Recognising a complete set that declares one file more than it posted (#939).
 *
 * Each negative fixture breaks exactly one part of the rule, so a check dropped from the
 * implementation fails here rather than passing on the strength of another.
 */
class PhantomTrailingFileTest extends TestCase
{
    #[Test]
    public function a_complete_set_ending_in_its_remainder_volume_holds_what_it_declares_less_one(): void
    {
        $this->assertSame(12, PhantomTrailingFile::heldCount(PhantomTrailingSets::base()));
        $this->assertSame(12, PhantomTrailingFile::declaredFiles(PhantomTrailingSets::base(), 13));
    }

    #[Test]
    public function a_remainder_volume_may_equal_the_previous_count(): void
    {
        $this->assertSame(12, PhantomTrailingFile::heldCount(PhantomTrailingSets::remainderEqualToPrevious()));
    }

    #[Test]
    public function the_rule_reads_bare_binary_names_as_well_as_stored_subjects(): void
    {
        $this->assertSame(12, PhantomTrailingFile::heldCount(PhantomTrailingSets::binaryNames(PhantomTrailingSets::baseFiles())));
    }

    #[Test]
    public function subject_order_does_not_matter(): void
    {
        $this->assertSame(12, PhantomTrailingFile::heldCount(array_reverse(PhantomTrailingSets::base())));
    }

    /**
     * @param  list<string>  $subjects
     */
    #[Test]
    #[DataProvider('unchangedSets')]
    public function a_set_breaking_one_condition_keeps_its_declared_count(array $subjects, int $declared): void
    {
        $this->assertNull(PhantomTrailingFile::heldCount($subjects));
        $this->assertSame($declared, PhantomTrailingFile::declaredFiles($subjects, $declared));
    }

    /**
     * @return array<string, array{list<string>, int}>
     */
    public static function unchangedSets(): array
    {
        return [
            'condition 2: part3 absent, series intact' => [PhantomTrailingSets::ordinalGap(), 13],
            'condition 2: one subject declares 14' => [PhantomTrailingSets::disagreeingTotal(), 13],
            'condition 2: part2 posted twice' => [PhantomTrailingSets::duplicatedOrdinal(), 13],
            'condition 2: every subject declares 14' => [PhantomTrailingSets::declaresTwoMore(), 14],
            'condition 3: volumes before parts' => [PhantomTrailingSets::volumesBeforeParts(), 13],
            'condition 1: one unreadable filename' => [PhantomTrailingSets::unreadableFilename(), 13],
            'condition 4: series gap' => [PhantomTrailingSets::seriesGap(), 13],
            'condition 4: last volume is not a remainder' => [PhantomTrailingSets::lastVolumeNotARemainder(), 13],
            'condition 4: even split' => [PhantomTrailingSets::evenSplit(), 13],
            'condition 4: a single recovery volume' => [PhantomTrailingSets::singleRecoveryVolume(), 8],
        ];
    }

    #[Test]
    public function no_subjects_is_no_match(): void
    {
        $this->assertNull(PhantomTrailingFile::heldCount([]));
    }
}
