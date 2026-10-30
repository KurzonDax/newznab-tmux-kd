<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nzb;

use App\Services\Nzb\NzbParserService;
use PHPUnit\Framework\TestCase;

class NzbParserServiceTest extends TestCase
{
    public function test_generated_par2_subject_retains_index_detection_after_counter_suffix(): void
    {
        $parser = new NzbParserService;
        $this->assertTrue($parser->detectPar2IndexFile('"recovery.par2" yEnc (1/1)'));
        $this->assertTrue($parser->detectPar2IndexFile('ordinary.par2'));
        $this->assertFalse($parser->detectPar2IndexFile('"payload.mkv" yEnc (1/4)'));
        $this->assertFalse($parser->detectPar2IndexFile('"not.par2.exe" yEnc (1/1)'));
    }

    public function test_it_keeps_per_segment_numbering_alongside_the_message_ids(): void
    {
        $nzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <file poster="poster@example.com" date="1700000000" subject="&quot;feature.mkv&quot; yEnc (3/3)">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="750000" number="1">seg-one@example.com</segment>
      <segment bytes="750000" number="3">seg-three@example.com</segment>
      <segment bytes="500000" number="2">seg-two@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $files = (new NzbParserService)->parseNzbFileList($nzb);

        $this->assertCount(1, $files);
        $file = $files[0];
        $this->assertSame(
            ['seg-one@example.com', 'seg-three@example.com', 'seg-two@example.com'],
            $file['segments'],
        );
        $this->assertSame([1, 3, 2], $file['segmentNumbers'], 'Numbering keeps the posted order, gaps and all.');
        $this->assertSame(2000000, (int) $file['size']);
    }

    public function test_missing_number_attributes_become_zero(): void
    {
        $nzb = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <file poster="poster@example.com" date="1700000000" subject="&quot;feature.mkv&quot; yEnc (1/1)">
    <groups><group>alt.binaries.test</group></groups>
    <segments>
      <segment bytes="750000">seg-one@example.com</segment>
    </segments>
  </file>
</nzb>
XML;

        $files = (new NzbParserService)->parseNzbFileList($nzb);

        $this->assertSame([0], $files[0]['segmentNumbers']);
    }

    public function test_file_list_parsing_scales_linearly_with_file_count(): void
    {
        $parser = new NzbParserService;
        $bestSeconds = [];

        foreach ([10000, 40000] as $fileCount) {
            $nzb = $this->singleSegmentNzb($fileCount);
            $bestSeconds[$fileCount] = INF;

            for ($run = 0; $run < 3; $run++) {
                $started = hrtime(true);
                $files = $parser->parseNzbFileList($nzb);
                $bestSeconds[$fileCount] = min($bestSeconds[$fileCount], (hrtime(true) - $started) / 1e9);

                $this->assertCount($fileCount, $files);
                $this->assertSame([$fileCount], array_values(array_unique(array_column($files, 'nzbFileCount'))));
            }
        }

        $this->assertLessThan(
            8,
            $bestSeconds[40000] / $bestSeconds[10000],
            sprintf('10,000 files parsed in %.3f s, 40,000 in %.3f s.', $bestSeconds[10000], $bestSeconds[40000]),
        );
    }

    private function singleSegmentNzb(int $fileCount): string
    {
        $files = '';
        for ($i = 1; $i <= $fileCount; $i++) {
            $files .= sprintf(
                '<file poster="poster@example.com" date="1700000000" subject="[%1$d/%2$d] &quot;f%1$d.bin&quot; yEnc (1/1)">'
                .'<groups><group>alt.binaries.test</group></groups>'
                .'<segments><segment bytes="750000" number="1">seg-%1$d@example.com</segment></segments>'
                ."</file>\n",
                $i,
                $fileCount,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n"
            .$files
            .'</nzb>';
    }
}
