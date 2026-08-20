<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NzbParserServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function filesTotalProvider(): array
    {
        return [
            'obfuscated single-segment subject' => ['[37/240] - "9f2c1b" yEnc (1/240)', 240],
            'file index without a parts suffix' => ['[0/240] - "9f2c1b" yEnc', 240],
            'file index mid-subject' => ['Some.Post [01/12] - "file.part01.rar" yEnc (1/50)', 12],
            'no file index at all' => ['"Some.Movie.2024.part01.rar" yEnc (1/50)', 0],
            'parenthesised file index is not a file index' => ['Some.Post (1/12) - "file.rar" yEnc (1/50)', 0],
            'zero file total' => ['[1/0] - "file.rar" yEnc (1/50)', 0],
            'first index wins' => ['[3/9] - "Foo [2/8].mkv" yEnc (1/10)', 9],
        ];
    }

    #[DataProvider('filesTotalProvider')]
    public function test_it_extracts_the_file_index_total(string $subject, int $expected): void
    {
        $this->assertSame($expected, (new NzbParserService)->extractFilesTotal($subject));
    }
}
