<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReleaseNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseNameNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizationProvider(): array
    {
        return [
            'quoted part file' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar"',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'quoted par2 volume' => [
                '"HookupHotshot - 2020 Flashback Highlight Compilation.vol012+10.par2"',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'unquoted part file' => [
                'HookupHotshot - 2020 Flashback Highlight Compilation.part018.rar',
                'HookupHotshot - 2020 Flashback Highlight Compilation',
            ],
            'bare archive extension' => ['Some.Release.Name.rar', 'Some.Release.Name'],
            'seven zip' => ['Some.Release.Name.7z', 'Some.Release.Name'],
            'zip' => ['Some.Release.Name.zip', 'Some.Release.Name'],
            'plain par2' => ['Some.Release.Name.par2', 'Some.Release.Name'],
            'quotes only' => ['"Some.Release.Name"', 'Some.Release.Name'],
            'leading multipart counter' => ['[10/88] Some.Release.Name', 'Some.Release.Name'],
            'trailing yenc marker' => ['Some.Release.Name yEnc', 'Some.Release.Name'],
            'counter and yenc outside filename quotes' => [
                '[10/88] "Some.Release.Name.part009.rar" yEnc',
                'Some.Release.Name',
            ],
            'surrounding whitespace' => ['  Some.Release.Name  ', 'Some.Release.Name'],
            'already clean' => ['Some.Release.Name', 'Some.Release.Name'],
            'dash separator between counter and quoted filename' => [
                '[51/60] - "FC2.PPV.4963596.Foo.1080p.mp4" yEnc',
                'FC2.PPV.4963596.Foo.1080p.mp4',
            ],
            'short quoted filename behind a dash' => [
                '[10/10] - "p4.1080p.mp4" yEnc',
                'p4.1080p.mp4',
            ],
            'trailing size annotation in megabytes' => [
                '[02/24] - "Show.S11E02.HDTV.x264-GRP.nfo" - 288,96 MB yEnc',
                'Show.S11E02.HDTV.x264-GRP',
            ],
            'trailing size annotation in gigabytes' => [
                '[1/5] - "Foo.Bar.2020.1080p.mkv" - 7,87 GB yEnc',
                'Foo.Bar.2020.1080p.mkv',
            ],
            'underscore and bracket residue before the quotes' => [
                '[3/9]_-_"Some.Release.Name.part002.rar" yEnc',
                'Some.Release.Name',
            ],
            'quoted nfo companion file' => ['"Some.Release.Name.nfo"', 'Some.Release.Name'],
            'title outside the quotes wins over the filename' => [
                '[1/3] - IP Scanner Pro 3.21-Sebaro - "IP Scanner Pro 3.21-Sebaro.rar" yEnc',
                'IP Scanner Pro 3.21-Sebaro - "IP Scanner Pro 3.21-Sebaro.rar"',
            ],
            'title before an inline counter is left alone' => [
                'IP Scanner Pro 3.21-Sebaro - [1/3] - "IP Scanner Pro 3.21-Sebaro.rar" yEnc',
                'IP Scanner Pro 3.21-Sebaro - [1/3] - "IP Scanner Pro 3.21-Sebaro.rar"',
            ],
        ];
    }

    #[DataProvider('normalizationProvider')]
    public function test_release_names_are_normalized(string $name, string $expected): void
    {
        $this->assertSame($expected, ReleaseNameNormalizer::normalize($name));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function untouchedProvider(): array
    {
        return [
            'media extension is part of the name' => ['Some.Release.Name.1080p.mkv'],
            'inner quotes' => ['Say "Hello" To.The.Bad.Guy.1983'],
            'partial quote' => ['"Unbalanced.Release.Name'],
            'part number without archive extension' => ['Some.Release.Name.part018'],
            'rar inside the name' => ['Some.rar.Documentary.2019.1080p'],
            'bitrate is not a size annotation' => ['Some.Album.2019.320kbps'],
            'resolution is not a size annotation' => ['Some.Release.Name.2160p'],
            'leading non-ascii letter is not residue' => ['Ärzte.Live.2019.1080p'],
        ];
    }

    #[DataProvider('untouchedProvider')]
    public function test_names_without_raw_subject_leftovers_are_untouched(string $name): void
    {
        $this->assertSame($name, ReleaseNameNormalizer::normalize($name));
    }

    #[DataProvider('normalizationProvider')]
    public function test_normalization_is_idempotent(string $name, string $expected): void
    {
        $normalized = ReleaseNameNormalizer::normalize($name);

        $this->assertSame($expected, $normalized);
        $this->assertSame($normalized, ReleaseNameNormalizer::normalize($normalized));
    }
}
