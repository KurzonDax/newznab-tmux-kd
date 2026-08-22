<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Utf8;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Utf8Test extends TestCase
{
    #[Test]
    public function scrub_filename_removes_invalid_utf8_and_control_characters(): void
    {
        $filename = "Release\x00.Name\x1F\xED\xBD\xBF.mkv\x7F";

        self::assertSame('Release.Name.mkv', Utf8::scrubFilename($filename));
        self::assertTrue(mb_check_encoding(Utf8::scrubFilename($filename), 'UTF-8'));
    }

    #[Test]
    public function scrub_filename_preserves_plain_ascii(): void
    {
        self::assertSame('Release.Name.mkv', Utf8::scrubFilename('Release.Name.mkv'));
    }

    #[Test]
    public function scrub_filename_returns_empty_when_nothing_printable_remains(): void
    {
        self::assertSame('', Utf8::scrubFilename("\x00\x1F\x7F\xED\xBD\xBF"));
    }
}
