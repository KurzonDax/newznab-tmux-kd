<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\BrowseRoot;
use App\Enums\ReleaseResolution;
use App\Enums\ReleaseSource;
use App\Support\ReleaseQuality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseQualityTest extends TestCase
{
    /** @return array<string, array{?int, ?int, string, ReleaseResolution}> */
    public static function resolutionCases(): array
    {
        return [
            'name 2160p' => [null, null, 'Show.S01E01.2160p.WEB-DL-GRP', ReleaseResolution::Uhd],
            'name 1080i' => [null, null, 'Show.S01E01.1080i.HDTV-GRP', ReleaseResolution::FullHd],
            'name 720p upper case' => [null, null, 'SHOW.S01E01.720P.HDTV', ReleaseResolution::Hd],
            'name 576p' => [null, null, 'Show.576p.DVDRip', ReleaseResolution::Sd],
            'name 480i' => [null, null, 'Show.480i.DVD', ReleaseResolution::Sd],
            'name without a token' => [null, null, 'Some.Album.FLAC', ReleaseResolution::Unknown],
            'name token must stand alone' => [null, null, 'Show.x1080p', ReleaseResolution::Unknown],
            'leftmost name token wins' => [null, null, 'Show.720p.from.1080p', ReleaseResolution::Hd],
            'measured beats the name' => [1280, 720, 'Show.S01E01.2160p.WEB', ReleaseResolution::Hd],
            'measured without a name token' => [3840, 2160, 'Show.S01E01', ReleaseResolution::Uhd],
            'measured 4K by height alone' => [3000, 2100, 'x', ReleaseResolution::Uhd],
            'measured cinema 1080p' => [1920, 800, 'x', ReleaseResolution::FullHd],
            'measured 1080p by height alone' => [1440, 1080, 'x', ReleaseResolution::FullHd],
            'measured 720p by width alone' => [1280, 536, 'x', ReleaseResolution::Hd],
            'measured SD' => [720, 576, 'Show.1080p', ReleaseResolution::Sd],
            'measured width only' => [640, 0, 'Show.1080p', ReleaseResolution::Sd],
            'zero sizes are ignored' => [0, 0, 'Show.1080p', ReleaseResolution::FullHd],
            'null sizes are ignored' => [null, null, 'Show.1080p', ReleaseResolution::FullHd],
        ];
    }

    #[DataProvider('resolutionCases')]
    public function test_resolution_rule(?int $width, ?int $height, string $name, ReleaseResolution $expected): void
    {
        $this->assertSame($expected, ReleaseQuality::resolution($width, $height, $name));
    }

    /** @return array<string, array{string, ReleaseSource}> */
    public static function sourceCases(): array
    {
        return [
            'remux' => ['Movie.2020.1080p.BluRay.REMUX.AVC-GRP', ReleaseSource::Remux],
            'bdremux' => ['Movie.2020.1080p.BDRemux-GRP', ReleaseSource::Remux],
            'web-dl' => ['Show.S01E01.1080p.WEB-DL-GRP', ReleaseSource::Web],
            'web dl with a space' => ['Show S01E01 1080p WEB DL', ReleaseSource::Web],
            'webrip' => ['Show.S01E01.WEBRip.x264', ReleaseSource::Web],
            'web' => ['Show.S01E01.1080p.WEB.h264', ReleaseSource::Web],
            'bluray' => ['Movie.2020.BluRay.x264', ReleaseSource::BluRay],
            'blu-ray' => ['Movie.2020.Blu-Ray.x264', ReleaseSource::BluRay],
            'bdrip' => ['Movie.2020.BDRip.x264', ReleaseSource::BluRay],
            'brrip' => ['Movie.2020.BRRip.x264', ReleaseSource::BluRay],
            'dvdrip' => ['Show.S01E01.DVDRip.XviD', ReleaseSource::Dvd],
            'dvd' => ['Show.S01.DVD9', ReleaseSource::Unknown],
            'dvd alone' => ['Show.S01.DVD.x264', ReleaseSource::Dvd],
            'hdtv' => ['Show.S01E01.720p.HDTV.x264', ReleaseSource::Hdtv],
            'pdtv' => ['Show.S01E01.PDTV.XviD', ReleaseSource::Hdtv],
            'sdtv' => ['Show.S01E01.SDTV', ReleaseSource::Hdtv],
            'dsr' => ['Show.S01E01.DSR.XviD', ReleaseSource::Hdtv],
            'tvrip' => ['Show.S01E01.TVRip', ReleaseSource::Hdtv],
            'remux beats an earlier bluray token' => ['Movie.BluRay.1080p.REMUX', ReleaseSource::Remux],
            'web beats an earlier hdtv token' => ['Show.HDTV.WEB-DL', ReleaseSource::Web],
            'case insensitive' => ['show.s01e01.webrip', ReleaseSource::Web],
            'nothing' => ['Some.Album.FLAC', ReleaseSource::Unknown],
            'token must stand alone' => ['Show.WEBX.HDTVX', ReleaseSource::Unknown],
        ];
    }

    #[DataProvider('sourceCases')]
    public function test_source_rule(string $name, ReleaseSource $expected): void
    {
        $this->assertSame($expected, ReleaseQuality::source($name));
    }

    public function test_the_label_still_shows_the_leftmost_name_tokens(): void
    {
        $this->assertSame('1080p · WEB-DL', ReleaseQuality::label(BrowseRoot::Tv, 'Show.S01E01.1080p.WEB.DL-GRP'));
        $this->assertSame('2160p · BluRay', ReleaseQuality::label(BrowseRoot::Movies, 'Movie.2160p.BluRay.REMUX'));
        $this->assertSame('24-bit FLAC', ReleaseQuality::label(BrowseRoot::Audio, 'Album.24bit.FLAC'));
    }

    public function test_enum_values_and_labels_are_the_stored_contract(): void
    {
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn (ReleaseResolution $r): int => $r->value, ReleaseResolution::cases()));
        $this->assertSame(['Unknown', '4K', '1080p', '720p', 'SD'], array_map(fn (ReleaseResolution $r): string => $r->label(), ReleaseResolution::cases()));
        $this->assertSame([0, 1, 2, 3, 4, 5], array_map(fn (ReleaseSource $s): int => $s->value, ReleaseSource::cases()));
        $this->assertSame(['Unknown', 'WEB', 'Blu-ray', 'DVD', 'HDTV', 'Remux'], array_map(fn (ReleaseSource $s): string => $s->label(), ReleaseSource::cases()));
    }
}
