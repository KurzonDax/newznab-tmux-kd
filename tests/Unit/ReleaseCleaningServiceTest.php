<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseCleaningService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The generic cleaner runs for every group that has no naming regex of its own,
 * so it must not leave raw-subject leftovers in the searchname (#137).
 */
class ReleaseCleaningServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function subjectProvider(): array
    {
        return [
            'quoted part file' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar" yEnc',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'quoted par2 volume' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.vol012+10.par2" yEnc',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'quoted plain rar' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.rar" yEnc',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'hyphen separated yEnc marker' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.part002.rar" - yEnc',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'already clean subject is untouched' => [
                'Some.Release.S01E01.1080p.WEB-DL-GROUP yEnc',
                'Some.Release.S01E01.1080p.WEB-DL-GROUP',
            ],
        ];
    }

    #[DataProvider('subjectProvider')]
    public function test_generic_cleaner_strips_raw_subject_leftovers(string $subject, string $expected): void
    {
        $this->assertSame($expected, (new ReleaseCleaningService)->releaseCleanerHelper($subject));
    }
}
