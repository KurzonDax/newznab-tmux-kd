<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseRepair\MissingFileMatcher;
use App\Services\ReleaseRepair\OverviewLine;
use PHPUnit\Framework\TestCase;

/**
 * Whether an overview line read out of a group's window belongs to one particular release.
 *
 * A wrong attachment writes a message-ID into an NZB that fails at download time, so every one
 * of these is a case the matcher has to say no to.
 */
class MissingFileMatcherTest extends TestCase
{
    public function test_it_accepts_a_missing_file_of_the_same_post(): void
    {
        $this->assertTrue($this->matcher()->matches($this->line('[3/3] - "Example.part03.rar" yEnc (1/2)')));
    }

    public function test_it_rejects_a_file_the_nzb_already_holds(): void
    {
        // Appending it again would give the release two of the same file.
        $this->assertFalse($this->matcher()->matches($this->line('[1/3] - "Example.part01.rar" yEnc (1/2)')));
    }

    public function test_it_rejects_another_poster(): void
    {
        $this->assertFalse($this->matcher()->matches(
            $this->line('[3/3] - "Example.part03.rar" yEnc (1/2)', poster: 'someone.else@example.org')
        ));
    }

    public function test_it_rejects_a_repost_declaring_a_different_file_count(): void
    {
        $this->assertFalse($this->matcher()->matches($this->line('[3/9] - "Example.part03.rar" yEnc (1/2)')));
    }

    public function test_it_rejects_a_different_release_by_the_same_poster(): void
    {
        $this->assertFalse($this->matcher()->matches($this->line('[3/3] - "Different.part03.rar" yEnc (1/2)')));
    }

    public function test_it_rejects_a_file_index_outside_the_declared_range(): void
    {
        $this->assertFalse($this->matcher()->matches($this->line('[9/3] - "Example.part09.rar" yEnc (1/2)')));
    }

    public function test_masking_collapses_the_counters_that_differ_between_a_posts_files(): void
    {
        $this->assertSame(
            MissingFileMatcher::mask('[1/36] - "Rel.part01.rar" yEnc (1/211)'),
            MissingFileMatcher::mask('[27/36] - "Rel.part26.rar" yEnc (5/211)'),
        );

        $this->assertSame(
            MissingFileMatcher::mask('[1/36] - "Rel.r00" yEnc (1/211)'),
            MissingFileMatcher::mask('[9/36] - "Rel.r08" yEnc (3/211)'),
        );

        $this->assertSame(
            MissingFileMatcher::mask('[1/36] - "Rel.vol000+01.par2" yEnc (1/9)'),
            MissingFileMatcher::mask('[9/36] - "Rel.vol127+128.par2" yEnc (2/9)'),
        );
    }

    public function test_masking_keeps_different_releases_apart(): void
    {
        $this->assertNotSame(
            MissingFileMatcher::mask('[1/36] - "One.part01.rar" yEnc (1/211)'),
            MissingFileMatcher::mask('[1/36] - "Two.part01.rar" yEnc (1/211)'),
        );
    }

    public function test_it_reads_the_file_index_off_a_subject(): void
    {
        $this->assertSame(27, MissingFileMatcher::fileIndexOf('[27/36] - "x" yEnc (1/211)'));
        $this->assertNull(MissingFileMatcher::fileIndexOf('"x" yEnc (1/211)'));
    }

    private function matcher(): MissingFileMatcher
    {
        return new MissingFileMatcher(
            poster: 'poster@example.org',
            declaredFiles: 3,
            heldSubjects: [
                '[1/3] - "Example.part01.rar" yEnc (1/2)',
                '[2/3] - "Example.part02.rar" yEnc (1/2)',
            ],
            heldIndices: [1, 2],
        );
    }

    private function line(string $subject, string $poster = 'poster@example.org'): OverviewLine
    {
        $line = OverviewLine::parse([
            'Subject' => $subject,
            'From' => $poster,
            'Message-ID' => '<found@example.local>',
            'Bytes' => '768000',
        ]);

        $this->assertNotNull($line, 'The fixture subject must parse.');

        return $line;
    }
}
