<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategorizeMovieTest extends TestCase
{
    public function test_bracketed_dvd_xvid_encode_is_an_sd_movie(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Example.Feature.(1986).{tmdb-123456}.-.[DVD][AC3.2.0][XviD]-GROUP',
            groupId: 0,
            groupName: 'alt.binaries.boneless',
            categorizeForeign: true,
            catWebDL: false,
        );

        $this->assertSame(Category::MOVIE_SD, (new MovieCategorizer)->categorize($context)->categoryId);

        $passable = (new MoviePipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
    }

    #[DataProvider('movieFormatNames')]
    public function test_standalone_format_tags_respect_movie_subcategory_precedence(string $name, int $expectedCategory): void
    {
        $context = new ReleaseContext(releaseName: $name, groupId: 0, catWebDL: false);

        $this->assertSame($expectedCategory, (new MovieCategorizer)->categorize($context)->categoryId);

        $passable = (new MoviePipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame($expectedCategory, $passable->bestResult->categoryId);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function movieFormatNames(): iterable
    {
        foreach (['XviD', 'DivX'] as $codec) {
            yield $codec.' bracketed' => ['Example.Feature.(1986).['.$codec.']-GROUP', Category::MOVIE_SD];
            yield $codec.' adjacent to DVD' => ['Example.Feature.(1986).[DVD]['.$codec.']', Category::MOVIE_SD];
            yield $codec.' before DVD' => ['Example.Feature.(1986).['.$codec.'][DVD]', Category::MOVIE_SD];
            yield $codec.' at start' => [$codec.'.Example.Feature.(1986)-GROUP', Category::MOVIE_SD];
            yield $codec.' at end' => ['Example.Feature.(1986).'.$codec, Category::MOVIE_SD];

            foreach (['.', ' ', '_', '-'] as $separator) {
                yield $codec.' separator '.$separator => ['Example.Feature.(1986)'.$separator.'DVD'.$separator.$codec.$separator.'GROUP', Category::MOVIE_SD];
            }
        }

        yield 'mixed case adjacent tags' => ['Example.Feature.(1986).[dVd][aC3.2.0][xViD]-GROUP', Category::MOVIE_SD];

        foreach (['DVDScr', 'DVDRip', 'VHSRip', 'HDTS', 'HDTS-LINE', 'ExtraScene', 'XviDvd'] as $format) {
            yield $format.' SD source' => ['Example.Feature.(1986).['.$format.']', Category::MOVIE_SD];
        }

        foreach (['DVD', 'DVD5', 'DVD9', 'DVD-R', 'DVDR', 'DVD-5', 'DVD-9'] as $disc) {
            yield $disc.' bracketed disc' => ['Example.Feature.(1986).['.$disc.']', Category::MOVIE_DVD];
            yield $disc.' legacy disc' => ['Example.Feature.(1986).'.$disc.'-GROUP', Category::MOVIE_DVD];
            yield $disc.' at start' => [$disc.'.Example.Feature.(1986)-GROUP', Category::MOVIE_DVD];
            yield $disc.' at end' => ['Example.Feature.(1986).'.$disc, Category::MOVIE_DVD];

            if ($disc !== 'DVD') {
                yield $disc.' with encode' => ['Example.Feature.(1986).['.$disc.'][XviD]', Category::MOVIE_DVD];
            }
        }

        yield 'HD takes precedence over SD encode' => ['Example.Feature.(1986).[DVD][XviD][1080p]', Category::MOVIE_HD];
        yield 'HD takes precedence over generic DVD' => ['Example.Feature.(1986).[DVD][1080p]', Category::MOVIE_HD];
        yield 'XvidHD takes precedence over generic DVD' => ['Example.Feature.(1986).[DVD][XvidHD]', Category::MOVIE_HD];
        yield 'explicit DVD disc retains precedence over HD' => ['Example.Feature.(1986).[DVD9][1080p]', Category::MOVIE_DVD];
        yield 'UHD takes precedence over DVD disc' => ['Example.Feature.(1986).[DVD9][XviD][2160p]', Category::MOVIE_UHD];
        yield 'XvidHD retains HD recognition' => ['Example.Feature.(1986).XvidHD-GROUP', Category::MOVIE_HD];
        yield 'Blu-ray takes precedence' => ['Example.Feature.(1986).Blu-ray.[DVD][XviD]', Category::MOVIE_BLURAY];
        yield 'foreign takes precedence' => ['Example.Feature.(1986).French.[DVD][XviD]', Category::MOVIE_FOREIGN];
    }

    #[DataProvider('nonFormatNames')]
    public function test_format_substrings_are_not_movie_evidence(string $name): void
    {
        $context = new ReleaseContext(releaseName: $name, groupId: 0);

        $this->assertFalse((new MovieCategorizer)->categorize($context)->isSuccessful());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonFormatNames(): iterable
    {
        foreach (['NotXviDCodec', 'NotDVDSource', 'NotXviD', 'XviDCodec', 'NotDivX', 'DivXCodec', 'NotDVD', 'DVDSource', 'NotDVD9', 'DVD9Source', 'NotDVDRip', 'DVDRipSource'] as $word) {
            yield $word => ['Example.Feature.(1986).'.$word.'-GROUP'];
            yield $word.' bracketed' => ['Example.Feature.(1986).['.$word.']-GROUP'];
        }
    }

    #[DataProvider('guardedFormatNames')]
    public function test_bracketed_formats_do_not_bypass_movie_pipe_skip_rules(string $name): void
    {
        $context = new ReleaseContext(releaseName: $name, groupId: 0);

        $this->assertTrue((new MovieCategorizer)->shouldSkip($context));

        $passable = (new MoviePipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertFalse($passable->bestResult->isSuccessful());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardedFormatNames(): array
    {
        return [
            'TV episode' => ['Example.Show.S01E02.[DVD][XviD]-GROUP'],
            'season pack' => ['Example.Show.S01.[DVD][DivX]-GROUP'],
            'anime episode' => ['Example.Anime.E01.[DVD][XviD]-GROUP'],
            'anime group' => ['[SubsPlease].Example.Feature.(1986).[DVD][XviD]'],
            'adult' => ['Example.Feature.XXX.(1986).[DVD][XviD]-GROUP'],
        ];
    }

    public function test_web_rip_movie_with_apes_in_title_stays_in_movie_category(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Kingdom Of the Planet of the Apes (2024) English (1080p DS4K WEB-Rip Itunes x265 HEVC 10Bit DDP5.1 Esub - R3TiR3D).mkv',
            groupId: 0,
        );
        $passable = new CategorizationPassable($context, debug: true);

        foreach ([new MoviePipe, new MusicPipe] as $pipe) {
            $passable = $pipe->handle($passable, fn (CategorizationPassable $result): CategorizationPassable => $result);
        }

        $this->assertSame(Category::MOVIE_WEBDL, $passable->bestResult->categoryId);
    }

    public function test_parentheses_can_delimit_a_movie_year_and_resolution(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Example(2024)1080p.x264.mkv',
            groupId: 0,
        );
        $passable = (new MoviePipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
    }

    public function test_genuine_high_resolution_flac_release_stays_lossless(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Artist - Album (2023) [FLAC] [24bit 96kHz]',
            groupId: 0,
        );
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_LOSSLESS, $passable->bestResult->categoryId);
    }

    public function test_genuine_vbr_mp3_release_stays_in_mp3_category(): void
    {
        $context = new ReleaseContext(releaseName: 'Artist - Album (2023) MP3 VBR WEB', groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_MP3, $passable->bestResult->categoryId);
    }

    public function test_genuine_soundtrack_release_stays_in_other_music_category(): void
    {
        $context = new ReleaseContext(releaseName: 'Movie Original Soundtrack (2024)', groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_OTHER, $passable->bestResult->categoryId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ambiguousNonMusicTokenProvider(): array
    {
        return [
            'ape prefix' => ['Kingdom Of the Planet of the Apes (2024) 1080p WEB-Rip.mkv'],
            'wav prefix' => ['The Wave Riders (2024) 1080p WEB-Rip.mkv'],
            'standalone ape video token' => ['The Ape (2024) 1080p WEB-Rip.mkv'],
            'vbr prefix' => ['The VBridge Sessions (2024) WEB'],
            'soundtrack prefix' => ['The Soundtracks Archive (2024)'],
            'mv underscore handle' => ['[1/3] - "@MV_WRLD ~ Batman Begins (2005) 1080p 10bit BluRay 60FPS x265 HEVC [Tamil + Telugu + Hindi DD2.0 - 224Kbps) + English(DD5.1 384 Kbps)] 3.7GB.mkv"'],
            'mv release group suffix' => ['Tanner.Hall.Forever.1080p.WEB-DL.H.264-MV'],
            'movie audio bitrate' => ['Chess.2006.AsianetMoviesHD.WEB.DL.H264.AAC2.0.192k-DDH'],
            'yearless movie audio bitrate' => ['Snehaveedu.1080p.AsianetMoviesHD.WEB.DL.H264.AAC2.0.192k-DDH'],
            'keep suffix before year' => ['Castle.Keep.1969'],
            'space-separated keep suffix before year' => ['Castle Keep 1969'],
            'deep suffix before year' => ['The.Deep.1977'],
            'sheep suffix before year' => ['Black.Sheep.2006'],
        ];
    }

    #[DataProvider('ambiguousNonMusicTokenProvider')]
    public function test_ambiguous_music_tokens_in_non_music_titles_do_not_categorize_as_music(string $releaseName): void
    {
        $context = new ReleaseContext(releaseName: $releaseName, groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertFalse($passable->bestResult->isSuccessful());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function genuineEpYearProvider(): array
    {
        return [
            'named EP' => ['Artist.Name-Some.EP.2019-GRP'],
            'numbered EP' => ['Artist.Name-2.EP.2020-GRP'],
        ];
    }

    #[DataProvider('genuineEpYearProvider')]
    public function test_genuine_ep_year_tokens_stay_in_other_music(string $releaseName): void
    {
        $context = new ReleaseContext(releaseName: $releaseName, groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_OTHER, $passable->bestResult->categoryId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function genuineMusicVideoProvider(): array
    {
        return [
            'tour concert film' => ['Taylor.Swift.The.Eras.Tour.2023.EXTENDED.1080p.WEBRip.x264.AAC5.1-YTS'],
            'mtv unplugged dvd' => ['Pearl.Jam.MTV.Unplugged.1992.NTSC.DVD.REMUX.DD5.1.mkv'],
            'mtv unplugged dvdrip' => ['Alice.In.Chains.MTV.Unplugged.1996.DVDRip.x264-HANDJOB'],
        ];
    }

    #[DataProvider('genuineMusicVideoProvider')]
    public function test_genuine_music_video_tokens_categorize_as_music_video_in_music_pipe(string $releaseName): void
    {
        $context = new ReleaseContext(releaseName: $releaseName, groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_VIDEO, $passable->bestResult->categoryId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function genuineBitrateTaggedMp3Provider(): array
    {
        return [
            'scene 320k' => ['VA-Ministry_Of_Sound_Anthems-2CD-2019-320k-GRP'],
            'web source 320k' => ['Artist-Album-(2020)-[320k]-WEB-GRP'],
        ];
    }

    #[DataProvider('genuineBitrateTaggedMp3Provider')]
    public function test_genuine_bitrate_tagged_music_stays_in_mp3_category(string $releaseName): void
    {
        $context = new ReleaseContext(releaseName: $releaseName, groupId: 0);
        $passable = (new MusicPipe)->handle(
            new CategorizationPassable($context),
            fn (CategorizationPassable $result): CategorizationPassable => $result,
        );

        $this->assertSame(Category::MUSIC_MP3, $passable->bestResult->categoryId);
    }
}
