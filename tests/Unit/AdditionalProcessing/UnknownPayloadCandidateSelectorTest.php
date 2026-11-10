<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use App\Services\AdditionalProcessing\UnknownPayloadCandidateSelector;
use PHPUnit\Framework\TestCase;

class UnknownPayloadCandidateSelectorTest extends TestCase
{
    public function test_split_set_files_are_the_eligible_files_at_the_largest_segment_count_in_nzb_order(): void
    {
        $contents = [
            $this->file('"k2f9q1" yEnc', 3, 0),
            $this->file('"release.7z.par2" yEnc', 3, 1),
            $this->file('"m4x7a0" yEnc', 2, 2),
            $this->file('"a8z3w5" yEnc', 3, 3),
            $this->file('"Notes.nfo" yEnc', 3, 4),
            $this->file('"b1c2d3.bin" yEnc', 3, 5),
        ];

        $files = (new UnknownPayloadCandidateSelector)->splitSetFiles($contents);

        $this->assertSame(['<s0-1>', '<s3-1>', '<s5-1>'], array_map(
            static fn (UnknownPayloadCandidate $file): string => $file->firstMessageId,
            $files,
        ));
        $this->assertSame([0, 3, 5], array_map(static fn (UnknownPayloadCandidate $file): int => $file->sourceIndex, $files));
        $this->assertSame([3, 3, 3], array_map(static fn (UnknownPayloadCandidate $file): int => $file->segmentCount, $files));
    }

    public function test_named_files_and_a_lone_largest_file_are_not_a_split_set(): void
    {
        $selector = new UnknownPayloadCandidateSelector;

        $this->assertSame([], $selector->splitSetFiles([
            $this->file('"Movie.7z.001" yEnc', 3),
            $this->file('"Movie.7z.002" yEnc', 3),
            $this->file('"Movie.7z.par2" yEnc', 1),
        ]));
        $this->assertSame([], $selector->splitSetFiles([
            $this->file('"k2f9q1" yEnc', 4),
            $this->file('"m4x7a0" yEnc', 3),
            $this->file('"a8z3w5" yEnc', 3),
        ]));
    }

    public function test_eligible_files_keep_every_eligible_file_in_nzb_order(): void
    {
        $contents = [
            $this->file('"k2f9q1" yEnc', 3),
            $this->file('"Notes.nfo" yEnc', 1),
            $this->file('"m4x7a0" yEnc', 1),
        ];

        $files = (new UnknownPayloadCandidateSelector)->eligibleFiles($contents);

        $this->assertSame([0, 2], array_map(static fn (UnknownPayloadCandidate $file): int => $file->sourceIndex, $files));
        $this->assertSame(1000, $files[0]->estimatedFirstSegmentBytes);
    }

    /** @return array<string, mixed> */
    private function file(string $title, int $segments, int $source = 0): array
    {
        return [
            'title' => $title,
            'segments' => array_map(static fn (int $part): string => '<s'.$source.'-'.$part.'>', range(1, $segments)),
            'partsactual' => $segments,
            'size' => 1000 * $segments,
        ];
    }
}
