<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Nzb\AudioOnlyImportCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudioOnlyImportCategoryTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, int|null}>
     */
    public static function subjectSets(): array
    {
        $hexFlac = array_map(
            static fn (int $n): string => hash('sha256', 'track-'.$n).'.flac',
            range(1, 32),
        );

        return [
            'bare hex-named flac files' => [$hexFlac, Category::MUSIC_LOSSLESS],
            'mp3 files with jpg, nfo and par2 sidecars' => [[
                '[1/6] - "01 - Track.mp3" yEnc (1/10)',
                '[2/6] - "02 - Track.mp3" yEnc (1/10)',
                '[3/6] - "cover.jpg" yEnc (1/1)',
                '[4/6] - "Album.nfo" yEnc (1/1)',
                '[5/6] - "Album.par2" yEnc (1/1)',
                '[6/6] - "Album.vol00+01.par2" yEnc (1/1)',
            ], Category::MUSIC_MP3],
            'quoted flac subjects' => [[
                '[01/12] - "01 - Track.flac" yEnc (1/20)',
                '[02/12] - "02 - Track.flac" yEnc (1/20)',
            ], Category::MUSIC_LOSSLESS],
            'flac files plus one mp3' => [[
                '"01 - Track.flac" yEnc (1/20)',
                '"02 - Track.flac" yEnc (1/20)',
                '"Bonus.mp3" yEnc (1/5)',
            ], Category::MUSIC_LOSSLESS],
            'only m4b files' => [[
                '"Book - Part 1.m4b" yEnc (1/50)',
                '"Book - Part 2.M4B" yEnc (1/50)',
            ], Category::MUSIC_AUDIOBOOK],
            'm4b plus mp3' => [[
                '"Book - Part 1.m4b" yEnc (1/50)',
                '"Book - Intro.mp3" yEnc (1/5)',
            ], Category::MUSIC_MP3],
            'flac files plus one rar' => [[
                '"01 - Track.flac" yEnc (1/20)',
                '"Album.rar" yEnc (1/20)',
            ], null],
            'flac plus mkv' => [[
                '"01 - Track.flac" yEnc (1/20)',
                '"Concert.mkv" yEnc (1/200)',
            ], null],
            'only par2 and nfo' => [[
                '"Album.par2" yEnc (1/1)',
                '"Album.nfo" yEnc (1/1)',
            ], null],
            'flac files plus a posted name with no dot' => [[
                '"01 - Track.flac" yEnc (1/20)',
                'k6cjis46D9f6YM9uJgi2mViEcnFdPTkN yEnc (1/20)',
            ], null],
            'only posted names with no dot' => [[
                'k6cjis46D9f6YM9uJgi2mViEcnFdPTkN yEnc (1/20)',
                '"Q2v7xZ8mL0pR3tY6" yEnc (1/20)',
            ], null],
            'no subjects' => [[], null],
        ];
    }

    /**
     * @param  list<string>  $subjects
     */
    #[DataProvider('subjectSets')]
    public function test_category_for_the_posted_files(array $subjects, ?int $expected): void
    {
        $this->assertSame($expected, AudioOnlyImportCategory::categoryFor($subjects));
    }
}
