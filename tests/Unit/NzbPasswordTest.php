<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbPassword;
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
}
