<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbPassword;
use App\Services\Par2Sidecar\SidecarNzb;
use App\Services\ReleaseRepair\NzbRepairDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NzbPasswordTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function headers(): array
    {
        $file = '<file poster="p" date="1" subject="s"><groups><group>a.b</group></groups><segments/></file>';

        return [
            'plain tag' => ['<head><meta type="password">secret</meta></head>', 'secret'],
            'upper-case type' => ['<head><meta type="PASSWORD">secret</meta></head>', 'secret'],
            'whitespace around type and value' => ['<head><meta type=" password ">  secret  </meta></head>', 'secret'],
            'entity-encoded value' => ['<head><meta type="password">a&amp;b</meta></head>', 'a&b'],
            'first non-empty of two' => [
                '<head><meta type="password"> </meta><meta type="password">first</meta><meta type="password">second</meta></head>',
                'first',
            ],
            'other metas only' => ['<head><meta type="category">TV</meta></head>', null],
            'no head' => ['<nzb>'.$file.'</nzb>', null],
            'empty tag' => ['<head><meta type="password"></meta></head>', null],
            'tag after the first file' => [
                '<nzb><head><meta type="category">TV</meta></head>'.$file.'<meta type="password">late</meta></nzb>',
                null,
            ],
        ];
    }

    #[DataProvider('headers')]
    public function test_it_reads_the_password_from_the_nzb_header(string $xml, ?string $expected): void
    {
        $this->assertSame($expected, NzbPassword::fromXml($xml));
    }

    public function test_it_reads_the_tag_from_a_full_nzb_document(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head>'
            .'<meta type="category">Movies</meta><meta type="password">it\'s $x</meta></head>'
            .'<file poster="p" date="1" subject="s"><groups><group>a.b</group></groups>'
            .'<segments><segment bytes="1" number="1">id@x</segment></segments></file></nzb>';

        $this->assertSame('it\'s $x', NzbPassword::fromXml($xml));
    }

    public function test_with_password_inserts_the_tag_inside_an_existing_head(): void
    {
        $xml = NzbPassword::withPassword(self::nzb('<head><meta type="category">TV</meta></head>'), 'secret');

        $this->assertStringContainsString('<head><meta type="password">secret</meta><meta type="category">TV</meta></head>', $xml);
        $this->assertSame('secret', NzbPassword::fromXml($xml));
        $this->assertReadable($xml, 'secret', files: 2);
    }

    public function test_with_password_creates_a_head_straight_after_the_nzb_start_tag(): void
    {
        $xml = NzbPassword::withPassword(self::nzb(), 'secret');

        $this->assertStringContainsString(
            '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="password">secret</meta></head><file ',
            $xml,
        );
        $this->assertReadable($xml, 'secret', files: 2);
    }

    public function test_with_password_round_trips_markup_and_replacement_characters_exactly(): void
    {
        $password = 'a<b&"c$1\\';

        foreach ([self::nzb(), self::nzb('<head></head>')] as $nzb) {
            $xml = NzbPassword::withPassword($nzb, $password);

            $this->assertSame($password, NzbPassword::fromXml($xml));
            $this->assertReadable($xml, $password, files: 2);
        }
    }

    public function test_sidecar_combining_keeps_the_target_password(): void
    {
        $target = NzbPassword::withPassword(self::nzb(), 'p');
        $source = '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
            .self::file('Example.vol00+01.par2 yEnc (1/1)', 'sidecar@x').'</nzb>';

        $combined = (new SidecarNzb)->append($target, $source);

        $this->assertSame('p', NzbPassword::fromXml($combined));
        $this->assertReadable($combined, 'p', files: 3);
    }

    public function test_late_header_merging_keeps_the_password(): void
    {
        $document = NzbRepairDocument::load(NzbPassword::withPassword(self::nzb(), 'p'));
        $this->assertNotNull($document);

        $this->assertSame(1, $document->addSegments([0 => [2 => 'late@x']]));
        $xml = $document->toXml();

        $this->assertStringContainsString('late@x', $xml);
        $this->assertSame('p', NzbPassword::fromXml($xml));
    }

    private function assertReadable(string $xml, string $password, int $files): void
    {
        $document = simplexml_load_string($xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, $document);
        $passwords = [];
        foreach ($document->head->meta as $meta) {
            if ((string) $meta['type'] === 'password') {
                $passwords[] = (string) $meta;
            }
        }
        $this->assertSame([$password], $passwords);
        $this->assertCount($files, $document->file);
    }

    private static function nzb(string $head = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.$head
            .self::file('Example.part01.rar yEnc (1/2)', 'one@x')
            .self::file('Example.part02.rar yEnc (1/1)', 'two@x')
            .'</nzb>';
    }

    private static function file(string $subject, string $messageId): string
    {
        return '<file poster="p" date="1" subject="'.$subject.'"><groups><group>a.b</group></groups>'
            .'<segments><segment bytes="1000" number="1">'.$messageId.'</segment></segments></file>';
    }
}
