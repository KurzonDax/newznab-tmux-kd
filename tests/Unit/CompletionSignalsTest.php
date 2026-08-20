<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\CompletionSignals;
use App\Services\Nzb\CompletionTally;
use PHPUnit\Framework\TestCase;

/**
 * The completion rule: segments held against segments declared, with the obfuscated
 * single-segment style measured in files rather than segments.
 */
class CompletionSignalsTest extends TestCase
{
    public function test_a_normal_multi_segment_post_measures_segments_against_declared_segments(): void
    {
        $signals = $this->tally([
            ['segments' => 50, 'declaredSegments' => 50],
            ['segments' => 58, 'declaredSegments' => 60],
        ]);

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertEqualsWithDelta(98.18, $signals->percentage(), 0.01);
    }

    public function test_a_complete_post_measures_one_hundred(): void
    {
        $signals = $this->tally([
            ['segments' => 10, 'declaredSegments' => 10],
            ['segments' => 20, 'declaredSegments' => 20],
        ]);

        $this->assertSame(100.0, $signals->percentage());
    }

    public function test_subjects_declaring_no_totals_keep_the_unknown_sentinel(): void
    {
        $signals = $this->tally([
            ['segments' => 5, 'declaredSegments' => 0],
            ['segments' => 5, 'declaredSegments' => 0],
        ]);

        $this->assertFalse($signals->isMeasurable());
        $this->assertSame(0.0, $signals->percentage());
    }

    public function test_the_obfuscated_single_segment_style_measures_files_not_segments(): void
    {
        // 220 files, each a lone segment whose parens repeat the collection-wide total of 240.
        $signals = $this->tally(array_fill(0, 220, [
            'segments' => 1,
            'declaredSegments' => 240,
            'declaredFiles' => 240,
        ]));

        $this->assertTrue($signals->isSingleSegmentStyle());
        $this->assertEqualsWithDelta(91.67, $signals->percentage(), 0.01);
    }

    public function test_the_files_present_are_the_numerator_not_the_repeated_total(): void
    {
        // Observed in prod: `[10/1083]` with the parens repeating 1083 and 220 files present.
        $signals = $this->tally(array_fill(0, 220, [
            'segments' => 1,
            'declaredSegments' => 1083,
            'declaredFiles' => 1083,
        ]));

        $this->assertTrue($signals->isSingleSegmentStyle());
        $this->assertEqualsWithDelta(20.31, $signals->percentage(), 0.01);
    }

    public function test_an_uncorroborated_repeated_total_is_not_read_as_a_file_count(): void
    {
        // No `[n/N]` survived, so nothing says the repeated 240 counts files rather than this
        // file's own segments. Measuring it in files would report 91.67% for a post that may be
        // one segment of each of 220 rar volumes.
        $signals = $this->tally(array_fill(0, 220, [
            'segments' => 1,
            'declaredSegments' => 240,
            'declaredFiles' => 0,
        ]));

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertFalse($signals->hasIrreconcilableSignals());
        $this->assertEqualsWithDelta(0.42, $signals->percentage(), 0.01);
    }

    public function test_a_normal_post_held_one_segment_per_file_is_never_measured_in_files(): void
    {
        // Equal-sized rar volumes declare identical totals, so a badly incomplete normal post has
        // the obfuscated style's exact shape. Its file index disagrees, which is what gives it
        // away -- and 5 of 50 declared files is emphatically not 100%.
        $signals = $this->tally(array_fill(0, 5, [
            'segments' => 1,
            'declaredSegments' => 500,
            'declaredFiles' => 50,
        ]));

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertTrue($signals->hasIrreconcilableSignals());
        $this->assertFalse($signals->isMeasurable());
        $this->assertSame(0.0, $signals->percentage());
    }

    public function test_a_file_index_rewritten_to_the_files_present_cannot_report_one_hundred(): void
    {
        // ReleaseProcessingService rewrites `totalfiles` to the files actually present once a
        // collection goes stale, so the file index comes back equal to the numerator. A release
        // that timed out half-arrived must not be recorded as complete.
        $signals = $this->tally(array_fill(0, 220, [
            'segments' => 1,
            'declaredSegments' => 240,
            'declaredFiles' => 220,
        ]));

        $this->assertTrue($signals->hasIrreconcilableSignals());
        $this->assertFalse($signals->isMeasurable());
        $this->assertSame(0.0, $signals->percentage());
    }

    public function test_a_complete_obfuscated_post_measures_one_hundred(): void
    {
        $signals = $this->tally(array_fill(0, 240, [
            'segments' => 1,
            'declaredSegments' => 240,
            'declaredFiles' => 240,
        ]));

        $this->assertSame(100.0, $signals->percentage());
    }

    public function test_index_posts_past_the_declared_file_total_cannot_push_completion_over_one_hundred(): void
    {
        $signals = $this->tally(array_fill(0, 242, [
            'segments' => 1,
            'declaredSegments' => 240,
            'declaredFiles' => 240,
        ]));

        $this->assertSame(100.0, $signals->percentage());
    }

    public function test_genuinely_single_part_files_are_not_the_obfuscated_style(): void
    {
        $signals = $this->tally(array_fill(0, 8, [
            'segments' => 1,
            'declaredSegments' => 1,
            'declaredFiles' => 8,
        ]));

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertSame(100.0, $signals->percentage());
    }

    public function test_differing_per_file_totals_are_not_the_obfuscated_style(): void
    {
        $signals = $this->tally([
            ['segments' => 1, 'declaredSegments' => 240, 'declaredFiles' => 240],
            ['segments' => 1, 'declaredSegments' => 120, 'declaredFiles' => 240],
        ]);

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertEqualsWithDelta(0.56, $signals->percentage(), 0.01);
    }

    public function test_a_lone_file_is_not_the_obfuscated_style(): void
    {
        $signals = $this->tally([
            ['segments' => 1, 'declaredSegments' => 240, 'declaredFiles' => 240],
        ]);

        $this->assertFalse($signals->isSingleSegmentStyle());
        $this->assertEqualsWithDelta(0.42, $signals->percentage(), 0.01);
    }

    public function test_an_empty_release_keeps_the_unknown_sentinel(): void
    {
        $signals = (new CompletionTally)->signals();

        $this->assertFalse($signals->isMeasurable());
        $this->assertSame(0.0, $signals->percentage());
    }

    /**
     * @param  list<array{segments:int, declaredSegments:int, declaredFiles?:int}>  $files
     */
    private function tally(array $files): CompletionSignals
    {
        $tally = new CompletionTally;

        foreach ($files as $file) {
            $tally->addFile($file['segments'], $file['declaredSegments'], $file['declaredFiles'] ?? 0);
        }

        return $tally->signals();
    }
}
