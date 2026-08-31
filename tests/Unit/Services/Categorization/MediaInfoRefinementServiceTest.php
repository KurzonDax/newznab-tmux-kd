<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Categorization;

use App\Models\Category;
use App\Services\Categorization\MediaInfoRefinementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MediaInfoRefinementServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{int, array<string, mixed>, int, string}>
     */
    public static function videoMappings(): iterable
    {
        yield 'movie UHD wins before codec' => [Category::MOVIE_OTHER, ['videowidth' => 3840, 'videoformat' => 'HEVC'], Category::MOVIE_UHD, 'video_uhd'];
        yield 'movie UHD wins before BDAV' => [Category::MOVIE_OTHER, ['videoheight' => 2160, 'containerformat' => 'BDAV'], Category::MOVIE_UHD, 'video_uhd'];
        yield 'tv UHD by height' => [Category::TV_OTHER, ['videoheight' => 2160], Category::TV_UHD, 'video_uhd'];
        yield 'xxx UHD' => [Category::XXX_OTHER, ['videowidth' => 3800], Category::XXX_UHD, 'video_uhd'];
        yield 'movie MPEG-PS' => [Category::MOVIE_OTHER, ['containerformat' => 'MPEG-PS'], Category::MOVIE_DVD, 'video_mpeg_ps'];
        yield 'xxx MPEG-PS' => [Category::XXX_OTHER, ['containerformat' => 'MPEG-PS'], Category::XXX_DVD, 'video_mpeg_ps'];
        yield 'tv MPEG-PS falls through to SD' => [Category::TV_OTHER, ['containerformat' => 'MPEG-PS', 'videowidth' => 720], Category::TV_SD, 'video_sd'];
        yield 'movie BDAV' => [Category::MOVIE_OTHER, ['containerformat' => 'BDAV'], Category::MOVIE_BLURAY, 'video_bdav'];
        yield 'tv BDAV falls through to HD' => [Category::TV_OTHER, ['containerformat' => 'BDAV', 'videoheight' => 720], Category::TV_HD, 'video_hd'];
        yield 'movie HEVC' => [Category::MOVIE_OTHER, ['videoformat' => 'HEVC'], Category::MOVIE_X265, 'video_hevc'];
        yield 'tv H.265 codec' => [Category::TV_OTHER, ['videocodec' => 'H.265'], Category::TV_X265, 'video_hevc'];
        yield 'xxx HEVC maps to x264 bucket' => [Category::XXX_OTHER, ['videoformat' => 'HEVC'], Category::XXX_X264, 'video_hevc'];
        yield 'xxx VC-1' => [Category::XXX_OTHER, ['videoformat' => 'VC-1'], Category::XXX_WMV, 'video_xxx_wmv'];
        yield 'xxx WMV codec' => [Category::XXX_OTHER, ['videocodec' => 'WMV3'], Category::XXX_WMV, 'video_xxx_wmv'];
        yield 'xxx MPEG-4 Visual' => [Category::XXX_OTHER, ['videoformat' => 'MPEG-4 Visual'], Category::XXX_XVID, 'video_xxx_xvid'];
        yield 'xxx XVID codec' => [Category::XXX_OTHER, ['videocodec' => 'XVID'], Category::XXX_XVID, 'video_xxx_xvid'];
        yield 'movie HD by width' => [Category::MOVIE_OTHER, ['videowidth' => 1280], Category::MOVIE_HD, 'video_hd'];
        yield 'tv SD by positive dimensions' => [Category::TV_OTHER, ['videowidth' => 640, 'videoheight' => 480], Category::TV_SD, 'video_sd'];
        yield 'xxx HD maps to x264' => [Category::XXX_OTHER, ['videoheight' => 720], Category::XXX_X264, 'video_hd'];
        yield 'xxx SD' => [Category::XXX_OTHER, ['videowidth' => 640], Category::XXX_SD, 'video_sd'];
        yield 'music with video and no duration' => [Category::MUSIC_OTHER, ['videowidth' => 640], Category::MUSIC_VIDEO, 'video_music'];
        yield 'music with short HD video' => [Category::MUSIC_OTHER, ['videowidth' => 1920, 'videoheight' => 1080, 'videoduration' => '00h:04m:00s'], Category::MUSIC_VIDEO, 'video_music'];
    }

    /**
     * @param  array<string, mixed>  $video
     */
    #[Test]
    #[DataProvider('videoMappings')]
    public function it_maps_video_media_info(int $currentCategoryId, array $video, int $expectedCategoryId, string $expectedRule): void
    {
        $decision = (new MediaInfoRefinementService)->decisionFor($currentCategoryId, $video, null);

        self::assertNotNull($decision);
        self::assertSame($expectedCategoryId, $decision->categoryId);
        self::assertSame($expectedRule, $decision->rule);
    }

    #[Test]
    public function feature_length_hd_video_does_not_promote_other_music_to_music_video(): void
    {
        $decision = (new MediaInfoRefinementService)->decisionFor(
            Category::MUSIC_OTHER,
            ['videowidth' => 1920, 'videoheight' => 1080, 'videoduration' => '01h:05m:00s'],
            ['audioformat' => 'AC-3'],
        );

        self::assertNull($decision);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function audioMappings(): iterable
    {
        yield 'MPEG Audio' => ['MPEG Audio', Category::MUSIC_MP3, 'audio_mpeg'];
        yield 'FLAC' => ['FLAC', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'ALAC' => ['ALAC', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'Monkey audio' => ["Monkey's Audio", Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'APE' => ['APE', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'WavPack' => ['WavPack', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'TTA' => ['TTA', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'PCM' => ['PCM', Category::MUSIC_LOSSLESS, 'audio_lossless'];
        yield 'unknown' => ['AAC', Category::MUSIC_OTHER, 'audio_other'];
    }

    #[Test]
    #[DataProvider('audioMappings')]
    public function it_maps_audio_only_media_info(string $format, int $expectedCategoryId, string $expectedRule): void
    {
        $decision = (new MediaInfoRefinementService)->decisionFor(Category::MOVIE_OTHER, null, ['audioformat' => $format]);

        self::assertNotNull($decision);
        self::assertSame($expectedCategoryId, $decision->categoryId);
        self::assertSame($expectedRule, $decision->rule);
    }

    #[Test]
    public function it_leaves_missing_dimensions_and_ineligible_categories_unchanged(): void
    {
        $service = new MediaInfoRefinementService;

        self::assertNull($service->decisionFor(Category::MOVIE_OTHER, ['videowidth' => 0, 'videoheight' => null], null));
        self::assertNull($service->decisionFor(Category::MOVIE_HD, ['videowidth' => 1920], null));
        self::assertNull($service->decisionFor(Category::OTHER_MISC, null, ['audioformat' => 'FLAC']));
        self::assertNull($service->decisionFor(Category::BOOKS_UNKNOWN, ['videowidth' => 1920], null));
        self::assertNull($service->decisionFor(Category::GAME_OTHER, null, ['audioformat' => 'FLAC']));
        self::assertNull($service->decisionFor(Category::PC_OTHER, ['videowidth' => 1920], null));
        self::assertNull($service->decisionFor(Category::MUSIC_OTHER, null, null));
    }

    #[Test]
    public function video_media_takes_precedence_when_audio_is_also_present(): void
    {
        $decision = (new MediaInfoRefinementService)->decisionFor(
            Category::MOVIE_OTHER,
            ['videowidth' => 1280, 'videoheight' => 720],
            ['audioformat' => 'FLAC'],
        );

        self::assertNotNull($decision);
        self::assertSame(Category::MOVIE_HD, $decision->categoryId);
        self::assertSame('video_hd', $decision->rule);
    }
}
