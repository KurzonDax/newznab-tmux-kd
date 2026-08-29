<?php

namespace Tests\Unit;

use App\Facades\Yenc;
use App\Services\YencService;
use RuntimeException;
use Tests\TestCase;

class YencServiceTest extends TestCase
{
    private YencService $yencService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yencService = new YencService;
    }

    public function test_enabled_returns_true(): void
    {
        $this->assertTrue($this->yencService->enabled());
    }

    public function test_encode_and_decode_round_trip(): void
    {
        $originalData = 'Hello, World! This is a test of yEnc encoding.';
        $filename = 'test.txt';

        // Encode
        $encoded = $this->yencService->encode($originalData, $filename);

        // Verify encoding contains headers
        $this->assertStringContainsString('=ybegin', $encoded);
        $this->assertStringContainsString('=yend', $encoded);
        $this->assertStringContainsString('name=test.txt', $encoded);
        $this->assertStringContainsString('crc32=', $encoded);

        // Decode
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($originalData, $decoded);
    }

    public function test_encode_respects_line_length(): void
    {
        $data = str_repeat('A', 500);
        $filename = 'test.txt';

        $encoded = $this->yencService->encode($data, $filename, 100);

        // Check that no content line exceeds 100 characters
        $lines = explode("\r\n", $encoded);
        foreach ($lines as $line) {
            // Skip header and trailer lines
            if (str_starts_with($line, '=y')) {
                continue;
            }
            $this->assertLessThanOrEqual(100, strlen($line));
        }
    }

    public function test_encode_caps_line_length_at_254(): void
    {
        $data = 'Test data';
        $filename = 'test.txt';

        $encoded = $this->yencService->encode($data, $filename, 300);

        // Should contain line=254 in header
        $this->assertStringContainsString('line=254', $encoded);
    }

    public function test_encode_throws_exception_for_invalid_line_length(): void
    {
        $this->expectException(RuntimeException::class);

        $this->yencService->encode('data', 'test.txt', 0);
    }

    public function test_decode_returns_false_for_non_yenc_data(): void
    {
        $nonYencData = 'This is just plain text.';

        $result = $this->yencService->decode($nonYencData);

        $this->assertFalse($result);
    }

    public function test_decode_ignore_returns_original_for_non_yenc_data(): void
    {
        $nonYencData = 'This is just plain text.';

        $result = $this->yencService->decodeIgnore($nonYencData);

        $this->assertEquals($nonYencData, $result);
    }

    public function test_decode_ignore_decodes_yenc_data(): void
    {
        $originalData = 'Test content for yEnc';
        $encoded = $this->yencService->encode($originalData, 'test.txt');

        $decoded = $this->yencService->decodeIgnore($encoded);

        $this->assertEquals($originalData, $decoded);
    }

    public function test_decode_with_crc_status_accepts_a_valid_trailer_checksum(): void
    {
        $article = "=ybegin line=128 size=5 name=hello.txt\r\nr\x8f\x96\x96\x99\r\n=yend size=5 crc32=f7d18982";

        $result = $this->yencService->decodeWithCrcStatus($article);

        $this->assertSame('Hello', $result->data);
        $this->assertFalse($result->crcFailed);
    }

    public function test_decode_with_crc_status_flags_a_payload_that_disagrees_with_its_trailer_checksum(): void
    {
        $article = "=ybegin line=128 size=5 name=hello.txt\r\nr\x8f\x96\x96\x98\r\n=yend size=5 crc32=f7d18982";

        $result = $this->yencService->decodeWithCrcStatus($article);

        $this->assertSame('Helln', $result->data);
        $this->assertTrue($result->crcFailed);
    }

    public function test_decode_with_crc_status_accepts_an_article_without_a_trailer_checksum(): void
    {
        $article = "=ybegin line=128 size=5 name=hello.txt\r\nr\x8f\x96\x96\x99\r\n=yend size=5";

        $result = $this->yencService->decodeWithCrcStatus($article);

        $this->assertSame('Hello', $result->data);
        $this->assertFalse($result->crcFailed);
    }

    public function test_decode_with_crc_status_uses_the_part_checksum_for_multipart_articles(): void
    {
        $article = "=ybegin part=1 line=128 size=10 name=hello.txt\r\n"
            ."=ypart begin=1 end=5\r\n"
            ."r\x8f\x96\x96\x99\r\n"
            .'=yend size=5 pcrc32=f7d18982 crc32=00000000';

        $result = $this->yencService->decodeWithCrcStatus($article);

        $this->assertSame('Hello', $result->data);
        $this->assertFalse($result->crcFailed);
    }

    public function test_decode_with_crc_status_uses_a_legacy_multipart_trailer_checksum_without_ypart(): void
    {
        $article = "=ybegin part=1 line=128 size=10 name=hello.txt\r\n"
            ."r\x8f\x96\x96\x99\r\n"
            .'=yend size=5 part=1 pcrc32=f7d18982 crc32=00000000';

        $result = $this->yencService->decodeWithCrcStatus($article);

        $this->assertSame('Hello', $result->data);
        $this->assertFalse($result->crcFailed);
    }

    public function test_is_yenc_encoded_returns_true_for_yenc(): void
    {
        $encoded = $this->yencService->encode('test', 'test.txt');

        $this->assertTrue($this->yencService->isYencEncoded($encoded));
    }

    public function test_is_yenc_encoded_returns_false_for_plain_text(): void
    {
        $this->assertFalse($this->yencService->isYencEncoded('plain text'));
    }

    public function test_extract_metadata_returns_metadata(): void
    {
        $data = 'Test content';
        $encoded = $this->yencService->encode($data, 'myfile.dat', 128, true);

        $metadata = $this->yencService->extractMetadata($encoded);

        $this->assertNotNull($metadata);
        $this->assertEquals('myfile.dat', $metadata['name']);
        $this->assertEquals(strlen($data), $metadata['size']);
        $this->assertEquals(128, $metadata['line']);
        $this->assertNotNull($metadata['crc32']);
    }

    public function test_extract_metadata_returns_null_for_non_yenc(): void
    {
        $metadata = $this->yencService->extractMetadata('plain text');

        $this->assertNull($metadata);
    }

    public function test_encode_without_crc32(): void
    {
        $encoded = $this->yencService->encode('test', 'test.txt', 128, false);

        $this->assertStringNotContainsString('crc32=', $encoded);
    }

    public function test_encode_with_special_characters(): void
    {
        // Test with characters that need escaping (NULL, TAB, LF, CR, space, ., =)
        $data = "Hello\x00World\tTest\nNew\rLine . = End";

        $encoded = $this->yencService->encode($data, 'special.bin');
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($data, $decoded);
    }

    public function test_encode_with_binary_data(): void
    {
        // Test with all possible byte values
        $data = '';
        for ($i = 0; $i < 256; $i++) {
            $data .= chr($i);
        }

        $encoded = $this->yencService->encode($data, 'binary.bin');
        $decoded = $this->yencService->decode($encoded);

        $this->assertEquals($data, $decoded);
    }

    public function test_service_can_be_resolved_from_container(): void
    {
        $service = app(YencService::class);

        $this->assertInstanceOf(YencService::class, $service);
    }

    public function test_facade_works(): void
    {
        $this->assertTrue(Yenc::enabled());
    }
}
