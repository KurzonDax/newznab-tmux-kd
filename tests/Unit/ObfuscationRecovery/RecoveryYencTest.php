<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryYenc;
use PHPUnit\Framework\TestCase;

final class RecoveryYencTest extends TestCase
{
    public function test_terminal_declarations_stop_immediately_after_the_range_without_payload(): void
    {
        $decoder = new RecoveryYenc(16384, true, true);
        $this->assertFalse($decoder->line('=ybegin part=3 total=3 line=128 size=1500000 name=opaque'));
        $this->assertTrue($decoder->line('=ypart begin=1433601 end=1500000'));
        $article = $decoder->prefix();
        $this->assertSame('', $article->data);
        $this->assertSame(3, $article->part);
        $this->assertSame(1433601, $article->begin);
        $this->assertFalse($article->complete);
    }

    public function test_complete_article_validates_offsets_and_supplied_crc(): void
    {
        $payload = implode('', array_map(chr(...), range(0, 255)));
        $decoder = new RecoveryYenc(1048576);
        $decoder->line('=ybegin part=2 total=4 line=128 size=1000 name=file name.mkv');
        $decoder->line('=ypart begin=257 end=512');
        foreach ($this->encodedLines($payload) as $line) {
            $decoder->line($line);
        }
        $decoder->line('=yend size=256 part=2 pcrc32='.hash('crc32b', $payload));
        $article = $decoder->finish();
        $this->assertSame($payload, $article->data);
        $this->assertSame([2, 4, 1000, 257, 512], [$article->part, $article->total, $article->fileSize, $article->begin, $article->end]);
        $this->assertSame('file name.mkv', $article->filename);
        $this->assertTrue($article->complete);
        $this->assertTrue($article->crcPresent);
    }

    public function test_prefix_stops_at_the_decoded_cap_without_requesting_the_remaining_lines(): void
    {
        $decoder = new RecoveryYenc(16, prefixOnly: true);
        $decoder->line('=ybegin part=1 total=4 line=128 size=1000 name=file.mkv');
        $decoder->line('=ypart begin=1 end=256');
        $this->assertTrue($decoder->line(str_repeat('a', 100)));
        $article = $decoder->prefix();
        $this->assertSame(str_repeat(chr(ord('a') - 42), 16), $article->data);
        $this->assertFalse($article->complete);
    }

    public function test_truncated_escape_wrong_crc_and_trailing_payload_are_rejected(): void
    {
        foreach (['escape', 'crc', 'trailing'] as $case) {
            $decoder = new RecoveryYenc(100);
            try {
                $decoder->line('=ybegin line=128 size=1 name=file.par2');
                $decoder->line($case === 'escape' ? '=' : 'a');
                $decoder->line('=yend size=1 crc32='.($case === 'crc' ? '00000000' : hash('crc32b', '7')));
                $decoder->line('extra data');
                $this->fail('Malformed article was accepted: '.$case);
            } catch (\InvalidArgumentException $e) {
                $this->assertContains($e->getMessage(), ['invalid_yenc_escape', 'yenc_crc_mismatch', 'trailing_yenc_data']);
            }
        }
    }

    public function test_missing_crc_is_recorded_and_whole_article_limit_is_enforced_before_data(): void
    {
        $decoder = new RecoveryYenc(10);
        $decoder->line('=ybegin line=128 size=1 name=index.par2');
        $decoder->line('a');
        $decoder->line('=yend size=1');
        $this->assertFalse($decoder->finish()->crcPresent);
        $oversize = new RecoveryYenc(10);
        $this->expectExceptionMessage('decoded_article_cap');
        $oversize->line('=ybegin line=128 size=11 name=index.par2');
    }

    /** @return list<string> */
    private function encodedLines(string $bytes): array
    {
        $lines = [];
        foreach (str_split($bytes, 64) as $chunk) {
            $encoded = '';
            foreach (str_split($chunk) as $byte) {
                $value = (ord($byte) + 42) % 256;
                $encoded .= in_array($value, [0, 10, 13, 61], true) ? '='.chr(($value + 64) % 256) : chr($value);
            }
            $lines[] = $encoded;
        }

        return $lines;
    }
}
