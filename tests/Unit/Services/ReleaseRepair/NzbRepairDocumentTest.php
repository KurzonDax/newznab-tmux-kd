<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ReleaseRepair;

use App\Services\ReleaseRepair\NzbRepairDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhantomTrailingSets;

/**
 * Planning what is missing from a stored NZB, and writing the recovered segments back in.
 */
final class NzbRepairDocumentTest extends TestCase
{
    #[Test]
    public function accepted_segments_are_written_in_numeric_order_with_sibling_sized_bytes(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/5)', 'segments' => [
                1 => 'part1of5.Tok@host',
                3 => 'part3of5.Tok@host',
            ], 'bytes' => 1000],
        ]));

        $added = $document->addSegments([0 => [2 => 'part2of5.Tok@host', 4 => 'part4of5.Tok@host', 5 => 'part5of5.Tok@host']]);

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
    public function bytes_sums_every_segment_of_every_file(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/2)', 'segments' => [
                1 => 'part1of2.Tok@host',
                2 => 'part2of2.Tok@host',
            ], 'bytes' => 1000],
            ['subject' => 'Example.part02.rar yEnc (1/3)', 'segments' => [
                1 => 'part1of3.Other@host',
                2 => 'part2of3.Other@host',
                3 => 'part3of3.Other@host',
            ], 'bytes' => 250],
        ]));

        $this->assertNotNull($document);
        $this->assertSame(2 * 1000 + 3 * 250, $document->bytes());
    }

    #[Test]
    public function a_segment_without_usable_bytes_counts_as_zero(): void
    {
        $document = NzbRepairDocument::load(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n"
            .'  <file poster="poster@example.org" date="1700000000" subject="Example.rar yEnc (1/3)">'."\n"
            .'    <groups><group>alt.binaries.test</group></groups>'."\n"
            .'    <segments>'."\n"
            .'      <segment bytes="700" number="1">part1of3.Tok@host</segment>'."\n"
            .'      <segment number="2">part2of3.Tok@host</segment>'."\n"
            .'      <segment bytes="unknown" number="3">part3of3.Tok@host</segment>'."\n"
            .'    </segments>'."\n"
            .'  </file>'."\n"
            .'</nzb>'."\n"
        );

        $this->assertNotNull($document);
        $this->assertSame(700, $document->bytes());
    }

    #[Test]
    public function bytes_counts_added_segments_at_the_files_average_size(): void
    {
        $document = NzbRepairDocument::load($this->nzb([
            ['subject' => 'Example.part01.rar yEnc (1/5)', 'segments' => [
                1 => 'part1of5.Tok@host',
                3 => 'part3of5.Tok@host',
            ], 'bytes' => 1000],
        ]));

        $this->assertNotNull($document);
        $this->assertSame(2000, $document->bytes());

        $document->addSegments([0 => [2 => 'part2of5.Tok@host', 4 => 'part4of5.Tok@host', 5 => 'part5of5.Tok@host']]);

        $this->assertSame(5000, $document->bytes());
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

        $document->addSegments([0 => [3 => 'a&b-3-x@host']]);

        $reloaded = NzbRepairDocument::load($document->toXml());
        $this->assertNotNull($reloaded);
        $this->assertSame([1 => 'a&b-1-x@host', 2 => 'a&b-2-x@host', 3 => 'a&b-3-x@host'], $reloaded->segments()[0], 'The rewritten NZB must parse back with every ID decoded.');
    }

    #[Test]
    public function a_phantom_trailing_file_is_measured_against_the_files_held(): void
    {
        $document = NzbRepairDocument::load(PhantomTrailingSets::nzb(PhantomTrailingSets::base()));
        $this->assertNotNull($document);

        $this->assertSame(100.0, $document->measure()->percentage());
        $this->assertSame(100.0, $document->measure(PhantomTrailingSets::DECLARED)->percentage());
        $this->assertSame(PhantomTrailingSets::HELD, $document->measure(PhantomTrailingSets::DECLARED)->filesDeclared);
    }

    #[Test]
    public function a_set_that_is_not_a_phantom_trailing_file_keeps_its_declared_count(): void
    {
        $document = NzbRepairDocument::load(PhantomTrailingSets::nzb(PhantomTrailingSets::lastVolumeNotARemainder()));
        $this->assertNotNull($document);

        $this->assertEqualsWithDelta(12 / 13 * 100, $document->measure()->percentage(), 0.0001);
        $this->assertEqualsWithDelta(12 / 13 * 100, $document->measure(PhantomTrailingSets::DECLARED)->percentage(), 0.0001);
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
