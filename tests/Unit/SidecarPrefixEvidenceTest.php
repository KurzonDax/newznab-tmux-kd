<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\YencService;
use PHPUnit\Framework\TestCase;

class SidecarPrefixEvidenceTest extends TestCase
{
    public function test_decoding_preserves_the_same_articles_validated_raw_geometry(): void
    {
        $yenc = new YencService;
        $payload = "\x1a\x45\xdf\xa3".str_repeat('A', 16380).str_repeat('B', 751616);
        $article = $yenc->encode($payload, 'random');
        $article = str_replace('line=128 size=768000', 'part=1 total=3 line=128 size=2000000', $article);
        $article = preg_replace('/(^=ybegin[^\r]+\r\n)/', "$1=ypart begin=1 end=768000\r\n", $article);
        $article = str_replace('=yend size=768000', '=yend part=1 size=768000', $article);

        $decoded = $yenc->decodeWithCrcStatus($article);

        self::assertSame($payload, $decoded->data);
        self::assertNotNull($decoded->metadata);
        self::assertSame(2000000, $decoded->metadata->fileSize);
        self::assertSame(0, $decoded->metadata->offset);
        self::assertSame(1, $decoded->metadata->part);
        self::assertSame(3, $decoded->metadata->total);
        self::assertSame(768000, $decoded->metadata->length);
    }
}
