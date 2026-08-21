<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseRepair\DeclaredFileCount;
use PHPUnit\Framework\TestCase;

/**
 * Deriving a legacy release's declared file count from the subjects its NZB still carries.
 */
class DeclaredFileCountTest extends TestCase
{
    public function test_it_reads_the_bracket_file_index_the_subjects_agree_on(): void
    {
        $this->assertSame(36, $this->derive([
            '[27/36] - "Release.part26.rar" yEnc (1/211)',
            '[28/36] - "Release.part27.rar" yEnc (1/211)',
            '[29/36] - "Release.part28.rar" yEnc (1/211)',
        ]));
    }

    public function test_the_writers_synthesized_segment_counter_is_never_read_as_a_file_count(): void
    {
        // The NZB writer appends ` (1/<totalparts>)` to every subject. A regex that accepts the
        // parenthesised form reads 8,380 segments as 8,380 files -- which is how a sampled
        // release ended up "declaring" more files than the group holds articles.
        $this->assertSame(0, $this->derive([
            '"Release.part26.rar" yEnc (1/8380)',
            '"Release.part27.rar" yEnc (1/8380)',
        ]));
    }

    public function test_a_stray_bracket_on_one_subject_cannot_outvote_the_rest(): void
    {
        $this->assertSame(40, $this->derive([
            '[1/40] - "Release.part01.rar" yEnc (1/211)',
            '[2/40] - "Release.part02.rar" yEnc (1/211)',
            '[3/40] - "Release.part03.rar" yEnc (1/211)',
            '"Release [4/4] Special.rar" yEnc (1/211)',
        ]));
    }

    public function test_a_tie_breaks_to_the_larger_total(): void
    {
        // Under-declaring would hide the very file this is trying to notice.
        $this->assertSame(40, $this->derive([
            '[1/40] - "Release.part01.rar" yEnc (1/211)',
            '[1/12] - "Release.part02.rar" yEnc (1/211)',
        ]));
    }

    public function test_a_declaration_no_larger_than_what_we_hold_is_not_usable(): void
    {
        // Nothing to go looking for. A collection may legitimately carry one file past its
        // declared total -- the par2 volume -- so equality is not a shortfall either.
        $this->assertSame(0, $this->derive([
            '[1/2] - "Release.part01.rar" yEnc (1/211)',
            '[2/2] - "Release.part02.rar" yEnc (1/211)',
        ]));
    }

    public function test_subjects_with_no_bracket_token_declare_nothing(): void
    {
        $this->assertSame(0, $this->derive([
            '"Release.part01.rar" yEnc (1/211)',
            '"Release.part02.rar" yEnc (1/211)',
        ]));
    }

    public function test_an_empty_nzb_declares_nothing(): void
    {
        $this->assertSame(0, $this->derive([]));
    }

    /**
     * @param  list<string>  $subjects
     */
    private function derive(array $subjects): int
    {
        return (new DeclaredFileCount)->derive($subjects);
    }
}
