<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbFileSummaryReader;
use App\Services\Nzb\NzbParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NzbFileSummaryReaderTest extends TestCase
{
    #[DataProvider('unsafeXml')]
    public function test_rejects_unsafe_xml_before_returning_a_page(string $xml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'summary-');
        try {
            file_put_contents($path, gzencode($xml));
            $this->expectException(RuntimeException::class);
            (new NzbFileSummaryReader)->page($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{string}> */
    public static function unsafeXml(): array
    {
        return [
            'internal subset' => ['<!DOCTYPE nzb [<!ENTITY title "expanded">]><nzb><file subject="&title;"/></nzb>'],
            'external entity subset' => ['<!DOCTYPE nzb [<!ENTITY x SYSTEM "file:///etc/passwd">]><nzb><file subject="&x;"/></nzb>'],
            'empty subset' => ['<!DOCTYPE nzb []><nzb/>'],
            'deep nesting' => ['<nzb>'.str_repeat('<x>', 32).str_repeat('</x>', 32).'</nzb>'],
            'long text' => ['<nzb><file><segments><segment bytes="1">'.str_repeat('a', 65537).'</segment></segments></file></nzb>'],
            'long attribute' => ['<nzb><file subject="'.str_repeat('a', 65537).'"/></nzb>'],
        ];
    }

    public function test_small_summaries_match_the_legacy_parser(): void
    {
        $xml = '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd"><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><!-- comment --><file subject="&quot;名 &amp; file&quot;"><groups><group>alt.test</group></groups><segments><segment bytes="123" number="1"><![CDATA[id@example.invalid]]></segment><segment bytes="456" number="2">id2</segment></segments></file><file subject="empty"><groups/><segments/></file></nzb>';
        $expected = [];
        foreach ((new NzbParserService)->parseNzbFileList($xml) as $index => $file) {
            $expected[] = ['index' => $index, 'title' => $file['title'], 'size' => (int) $file['size']];
        }
        self::assertSame($expected, $this->readXml($xml)['files']);
    }

    public function test_handles_empty_prefixed_and_unnamespaced_input_and_later_pages(): void
    {
        self::assertSame(0, $this->readXml('<nzb/>')['total']);
        self::assertSame(1, $this->readXml('<n:nzb xmlns:n="http://www.newzbin.com/DTD/2003/nzb"><n:file subject="x"/></n:nzb>')['total']);
        $result = $this->readXml('<nzb>'.str_repeat('<file subject="duplicate"/>', 10001).'</nzb>', 101);
        self::assertSame(10001, $result['total']);
        self::assertSame([['index' => 10000, 'title' => 'duplicate', 'size' => 0]], $result['files']);
    }

    public function test_accepts_boundary_tokens_and_strips_control_characters_across_chunks(): void
    {
        $text = str_repeat('a', 65536);
        self::assertSame(1, $this->readXml('<nzb><!--'.$text.'--><file subject="ok"><segments><segment bytes="1">'.$text.'</segment></segments></file></nzb>')['total']);
        $tag = '<file subject="'.str_repeat('a', 65518).'"/>';
        self::assertSame(65536, strlen($tag));
        self::assertSame(1, $this->readXml('<nzb>'.$tag.'</nzb>')['total']);
        $xml = '<nzb><file subject="ab'."\x0F".'cd"><segments><segment bytes="1">'.str_repeat('x'."\x0F", 65536).'</segment></segments></file></nzb>';
        self::assertSame('abcd', $this->readXml($xml)['files'][0]['title']);
    }

    public function test_missing_file_is_not_found(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'summary-');
        unlink($path);
        $this->expectException(NotFoundHttpException::class);
        (new NzbFileSummaryReader)->page($path);
    }

    #[DataProvider('corruptGzip')]
    public function test_rejects_corruption_even_after_the_requested_page(string $gzip): void
    {
        $path = tempnam(sys_get_temp_dir(), 'summary-');
        try {
            file_put_contents($path, $gzip);
            $this->expectException(RuntimeException::class);
            (new NzbFileSummaryReader)->page($path, 1, 24);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, array{string}> */
    public static function corruptGzip(): array
    {
        $xml = '<nzb>'.str_repeat('<file subject="ok"/>', 25).'</nzb>';
        $gzip = gzencode($xml);
        $crc = $gzip;
        $crc[strlen($crc) - 8] = chr(ord($crc[strlen($crc) - 8]) ^ 1);

        return [
            'truncated gzip' => [substr($gzip, 0, -1)],
            'checksum' => [$crc],
            'trailing data' => [$gzip.'trailing'],
            'second gzip member' => [$gzip.$gzip],
            'malformed tail' => [gzencode(substr($xml, 0, -2))],
            'plain XML' => [$xml],
        ];
    }

    /** @return array{files: list<array{index: int, title: string, size: int}>, total: int, page: int, per: int, last_page: int} */
    private function readXml(string $xml, int $page = 1): array
    {
        $path = tempnam(sys_get_temp_dir(), 'summary-');
        try {
            file_put_contents($path, gzencode($xml));

            return (new NzbFileSummaryReader)->page($path, $page, 100);
        } finally {
            unlink($path);
        }
    }

    public function test_returns_only_the_requested_summaries_in_nzb_order(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'summary-');
        try {
            $files = str_repeat('<file subject="same &amp; 名"><segments><segment bytes="1000" number="1">secret@example.invalid</segment><segment bytes="24" number="2">other</segment></segments></file>', 25);
            file_put_contents($path, gzencode('<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.$files.'</nzb>'));
            self::assertSame([
                'files' => [['index' => 24, 'title' => 'same & 名', 'size' => 1024]],
                'total' => 25, 'page' => 2, 'per' => 24, 'last_page' => 2,
            ], (new NzbFileSummaryReader)->page($path, 2, 24));
        } finally {
            unlink($path);
        }
    }
}
