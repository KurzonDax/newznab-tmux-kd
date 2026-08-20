<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ReleaseRepair;

use App\Services\ReleaseRepair\NzbRepairDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Planning what is missing from a stored NZB, and writing the recovered segments back in.
 */
final class NzbRepairDocumentTest extends TestCase
{
    #[Test]
    public function it_plans_the_missing_segments_of_a_numbered_file(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/5)', 'segments' => [
                1 => 'part1of5.Tok@host',
                3 => 'part3of5.Tok@host',
            ]],
        ]));

        $this->assertNotNull($document);
        $plan = $document->plan();

        $this->assertTrue($plan->hasWork());
        $this->assertSame(3, $plan->synthesizedCount());
        $this->assertSame([2, 4, 5], array_keys($plan->files[0]->synthesized));
        $this->assertSame('part4of5.Tok@host', $plan->files[0]->synthesized[4]);
        $this->assertSame(2, $document->measure()->segmentsPresent);
        $this->assertSame(5, $document->measure()->segmentsDeclared);
    }

    #[Test]
    public function a_file_with_no_segments_has_nothing_to_derive_a_pattern_from(): void
    {
        // The token is per-file, so a file we saw nothing of is unknowable from the NZB alone.
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part02.rar yEnc (1/5)', 'segments' => []],
        ]));

        $plan = $document->plan();

        $this->assertFalse($plan->hasWork());
        $this->assertSame(1, $plan->filesWithNoSegments);
    }

    #[Test]
    public function a_random_id_poster_yields_no_work(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/5)', 'segments' => [
                1 => 'a4f1c9e2b7d3@ngPost',
                3 => '9e17b0d4a2c8@ngPost',
            ]],
        ]));

        $plan = $document->plan();

        $this->assertFalse($plan->hasWork());
        $this->assertSame(1, $plan->filesWithoutTemplate);
    }

    #[Test]
    public function a_complete_file_is_left_alone(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/2)', 'segments' => [
                1 => 'part1of2.Tok@host',
                2 => 'part2of2.Tok@host',
            ]],
        ]));

        $this->assertFalse($document->plan()->hasWork());
    }

    #[Test]
    public function accepted_segments_are_written_in_numeric_order_with_sibling_sized_bytes(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/5)', 'segments' => [
                1 => 'part1of5.Tok@host',
                3 => 'part3of5.Tok@host',
            ], 'bytes' => 1000],
        ]));

        $plan = $document->plan();
        $added = $document->addSegments([0 => $plan->files[0]->synthesized]);

        $this->assertSame(3, $added);
        $this->assertSame(100.0, $document->measure()->percentage());

        $xml = $document->toXml();
        preg_match_all('/number="(\d+)"/', $xml, $numbers);
        $this->assertSame(['1', '2', '3', '4', '5'], $numbers[1], 'Some readers concatenate in document order.');

        // `bytes` is advisory -- the true size is unknowable without fetching the article -- so
        // synthesized segments inherit what their siblings average.
        $this->assertStringContainsString('bytes="1000" number="2"', $xml);
    }

    #[Test]
    public function message_ids_containing_xml_metacharacters_survive_the_round_trip(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/3)', 'segments' => [
                1 => 'a&amp;b-1-x@host',
                2 => 'a&amp;b-2-x@host',
            ]],
        ]));

        $plan = $document->plan();
        $this->assertSame('a&b-3-x@host', $plan->files[0]->synthesized[3]);

        $document->addSegments([0 => $plan->files[0]->synthesized]);

        $reloaded = NzbRepairDocument::load($document->toXml());
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->plan()->hasWork(), 'The rewritten NZB must parse back as complete.');
    }

    #[Test]
    public function it_rejects_content_that_is_not_an_nzb(): void
    {
        $this->assertNull(NzbRepairDocument::load(''));
        $this->assertNull(NzbRepairDocument::load('not xml at all'));
        $this->assertNull(NzbRepairDocument::load('<?xml version="1.0"?><notnzb/>'));
    }

    /**
     * @param  list<array{subject: string, segments: array<int, string>, bytes?: int}>  $files
     */
    private function nzb(array $files): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";

        foreach ($files as $file) {
            $bytes = $file['bytes'] ?? 500;
            $xml .= '  <file poster="poster@example.org" date="1700000000" subject="'.$file['subject'].'">'."\n"
                .'    <groups><group>alt.binaries.test</group></groups>'."\n"
                .'    <segments>'."\n";

            foreach ($file['segments'] as $number => $messageId) {
                $xml .= '      <segment bytes="'.$bytes.'" number="'.$number.'">'.$messageId.'</segment>'."\n";
            }

            $xml .= '    </segments>'."\n".'  </file>'."\n";
        }

        return $xml.'</nzb>'."\n";
    }
}
