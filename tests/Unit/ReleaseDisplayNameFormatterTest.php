<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReleaseDisplayNameFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseDisplayNameFormatterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formattingProvider(): array
    {
        return [
            'filename derived name with container extension' => [
                'FC2.PPV.4963596.No.Amazing.voluptuous.fair.skinned.huge.ass.1080p.mp4',
                'FC2 PPV 4963596 No Amazing voluptuous fair skinned huge ass 1080p MP4',
            ],
            'two digit date is preserved' => [
                'nubiles.26.07.30.lilit.red.first.time.anal.1080p.mp4',
                'nubiles 26.07.30 lilit red first time anal 1080p MP4',
            ],
            'four digit date is preserved' => [
                'ClubSweethearts.2016.01.09.Anna.Rey.Solo.XXX.1080p.mp4',
                'ClubSweethearts 2016.01.09 Anna Rey Solo XXX 1080p MP4',
            ],
            'volume and episode digits are not a date' => ['Vol.2.E1', 'Vol 2 E1'],
            'single digit groups are not a date' => ['Show.1.2.3.Name', 'Show 1 2 3 Name'],
            'implausible month is not a date' => ['Bench.26.19.30.Result', 'Bench 26 19 30 Result'],
            'h264 codec' => ['Some.Movie.2019.1080p.BluRay.H.264.AAC', 'Some Movie 2019 1080p BluRay H.264 AAC'],
            'h265 codec lowercase' => ['Some.Movie.1080p.h.265.10bit', 'Some Movie 1080p h.265 10bit'],
            'dolby digital channel layout' => ['Some.Movie.1080p.DD5.1.x264', 'Some Movie 1080p DD5.1 x264'],
            'dolby digital plus channel layout' => ['Some.Movie.2160p.DDP5.1.HDR', 'Some Movie 2160p DDP5.1 HDR'],
            'bare channel layout' => ['Some.Movie.1080p.7.1.Atmos', 'Some Movie 1080p 7.1 Atmos'],
            'aac channel layout' => ['Some.Movie.1080p.AAC2.0.x264', 'Some Movie 1080p AAC2.0 x264'],
            'version number' => ['Some.Application.v1.2.3.Linux', 'Some Application v1.2.3 Linux'],
            'resolution tokens pass through' => ['Some.Movie.2160p.4k.WEB.DL', 'Some Movie 2160p 4k WEB DL'],
            'underscores become spaces' => ['Some_Release_Name_1080p', 'Some Release Name 1080p'],
            'channel layout is not read inside a longer number' => [
                'Some.Movie.Episode.5.1080p',
                'Some Movie Episode 5 1080p',
            ],
            'already spaced name is unchanged' => [
                'Some Movie 2019 1080p BluRay H.264 AAC',
                'Some Movie 2019 1080p BluRay H.264 AAC',
            ],
            'collapses runs of whitespace' => ['Some   Release  Name', 'Some Release Name'],
            'empty name' => ['', ''],
            'short trailing word is not an extension' => ['nubiles.lilit.red', 'nubiles lilit red'],
            'poster decoration and wrapping quotes are unwrapped' => [
                '- "Estella.Bathory.Watch.Estella.Bathory.very.hot.british.bbw.fucked.by.2.blacks.720p.SpankBang.com.mp4"',
                'Estella Bathory Watch Estella Bathory very hot british bbw fucked by 2 blacks 720p SpankBang com MP4',
            ],
            'counter decoration and yenc marker are unwrapped' => [
                '[10/88] - "Show.S01E02.1080p.mkv" yEnc',
                'Show S01E02 1080p MKV',
            ],
            'stacked counters unwrap fully' => ['[1/8] - [01/17] - "Name.mp4"', 'Name MP4'],
            'trailing size annotation is stripped' => ['- "Name.mp4" - 288,96 MB', 'Name MP4'],
            'a real title outside the quotes is not unwrapped' => [
                'Title - [1/3] - "Title.rar"',
                'Title - [1/3] - "Title rar"',
            ],
            'protected spans stay verbatim after the unwrap' => [
                '- "porndudecasting.26.08.21.sadie.schafer.2160p.mp4"',
                'porndudecasting 26.08.21 sadie schafer 2160p MP4',
            ],
        ];
    }

    #[DataProvider('formattingProvider')]
    public function test_it_formats_searchnames_for_display(string $searchName, string $expected): void
    {
        $this->assertSame($expected, ReleaseDisplayNameFormatter::format($searchName));
    }

    #[DataProvider('formattingProvider')]
    public function test_formatting_is_idempotent(string $searchName, string $expected): void
    {
        $this->assertSame($expected, ReleaseDisplayNameFormatter::format($expected));
    }

    public function test_it_caps_the_result_to_the_column_width(): void
    {
        $display = ReleaseDisplayNameFormatter::format(str_repeat('a.', 400));

        $this->assertSame(255, mb_strlen($display));
    }

    public function test_display_for_prefers_the_derived_name(): void
    {
        $release = (object) ['searchname' => 'Some.Release.Name', 'display_name' => 'Some Release Name'];

        $this->assertSame('Some Release Name', ReleaseDisplayNameFormatter::displayFor($release));
    }

    public function test_display_for_reads_objects_that_resolve_attributes_lazily(): void
    {
        $model = new class
        {
            /** @var array<string, string|null> */
            public array $attributes = ['searchname' => 'Some.Release.Name', 'display_name' => 'Some Release Name'];

            public function __get(string $name): ?string
            {
                return $this->attributes[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->attributes[$name]);
            }
        };

        $this->assertSame('Some Release Name', ReleaseDisplayNameFormatter::displayFor($model));
    }

    public function test_display_for_falls_back_to_the_searchname(): void
    {
        $this->assertSame(
            'Some.Release.Name',
            ReleaseDisplayNameFormatter::displayFor((object) ['searchname' => 'Some.Release.Name', 'display_name' => null])
        );
        $this->assertSame(
            'Some.Release.Name',
            ReleaseDisplayNameFormatter::displayFor(['searchname' => 'Some.Release.Name'])
        );
        $this->assertSame('', ReleaseDisplayNameFormatter::displayFor(null));
    }
}
