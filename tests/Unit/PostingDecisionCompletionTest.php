<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\PostingDecision;
use App\Services\CollectionReconciliation\PostingFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhantomTrailingSets;

/**
 * A reconciled posting's completion, for a poster that declares one file more than it posts (#939).
 */
class PostingDecisionCompletionTest extends TestCase
{
    #[Test]
    public function a_phantom_trailing_file_is_measured_against_the_files_held(): void
    {
        $decision = $this->decision(PhantomTrailingSets::binaryNames(PhantomTrailingSets::baseFiles()));

        $this->assertSame(100.0, $decision->completion());
        $this->assertSame(PhantomTrailingSets::DECLARED, $decision->declaredTotal);
    }

    #[Test]
    public function a_set_that_is_not_a_phantom_trailing_file_keeps_its_declared_count(): void
    {
        $files = PhantomTrailingSets::baseFiles();
        $files[12] = 'Show.Name.vol31+32.par2';

        $this->assertEqualsWithDelta(12 / 13 * 100, $this->decision(PhantomTrailingSets::binaryNames($files))->completion(), 0.0001);
    }

    /**
     * @param  list<string>  $subjects
     */
    private function decision(array $subjects): PostingDecision
    {
        $files = [];

        foreach ($subjects as $index => $subject) {
            $files[] = new PostingFile('1', 'file-'.$index, $subject, 'alt.binaries.test', 'poster@example.org', 1700000000, 1,
                [['number' => 1, 'messageid' => 'seg'.$index.'@host', 'bytes' => 900]]);
        }

        return new PostingDecision($files, PhantomTrailingSets::DECLARED, null, 'Show.Name', [], [], 'verified_union');
    }
}
