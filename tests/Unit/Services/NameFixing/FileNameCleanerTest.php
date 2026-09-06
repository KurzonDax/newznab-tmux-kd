<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\FileNameCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileNameCleanerTest extends TestCase
{
    public function test_clean_for_matching_rejects_url_shortcuts(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/Extreem Online Contact.url'));
    }

    public function test_clean_for_matching_rejects_generic_dvd_structure_files(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/VIDEO_TS/VIDEO_TS.VOB'));
        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/VIDEO_TS/VTS_01_1.VOB'));
        $this->assertFalse($cleaner->cleanForMatching('Film ;-)/1.jpg'));
    }

    public function test_clean_for_matching_keeps_useful_cover_names(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame('cover the fisher king', $cleaner->cleanForMatching('Film ;-)/cover the fisher king.jpg'));
    }

    public function test_normalize_candidate_title_removes_pdf_extension(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame('WOB Klassik 4.25', $cleaner->normalizeCandidateTitle('WOB Klassik 4.25.Pdf'));
    }

    public function test_format_search_name_keeps_scene_titles_dotted(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb',
            $cleaner->formatSearchName('Southern Charm S11E12 Even Further South 720p AMZN WEB-DL DDP2 0 H 264-NTb.mkv')
        );
    }

    public function test_descriptive_titles_accept_human_written_video_names(): void
    {
        $cleaner = new FileNameCleaner;

        foreach ([
            'Film ;-)/SupergirlPerv.avi',
            '2016-04-16 - Solana A - Before The Party 2.mp4',
            'My Wife Is In Heat.mkv',
            'Episode 3.mkv',
            'Díl 6.mkv',
            'media.mkv',
        ] as $filename) {
            $this->assertTrue($cleaner->isDescriptiveTitle($filename), $filename);
        }
    }

    public function test_descriptive_titles_reject_junk_and_obfuscated_video_names(): void
    {
        $cleaner = new FileNameCleaner;

        foreach ([
            'video1.mp4',
            'Movie 2.mkv',
            'VTS_01_1.VOB',
            'sample.mp4',
            'sample-featurette.mp4',
            'proof_featurette.avi',
            'thumbs.featurette.mkv',
            'feature-proof.avi',
            'abcdef0123456789abcdef0123456789.mp4',
            'My Feature disc1.mkv',
            'A.mov',
            'My Wife Is In Heat.txt',
        ] as $filename) {
            $this->assertFalse($cleaner->isDescriptiveTitle($filename), $filename);
        }
    }

    #[DataProvider('containerTitles')]
    public function test_container_titles_are_validated_without_a_filename_extension(string $title, bool $accepted): void
    {
        $this->assertSame($accepted, (new FileNameCleaner)->isDescriptiveContainerTitle($title));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function containerTitles(): array
    {
        return [
            'movie' => ['Con Air (1997)', true],
            'Unicode' => ['Duchové', true],
            'punctuation' => ['Star Wars: The Mandalorian and Grogu (2026)', true],
            'episode number' => ['Episode 3', false],
            'localized episode number' => ['Díl 6', false],
            'media placeholder' => ['media', false],
            'untitled' => ['Untitled', false],
            'empty' => ['', false],
            'whitespace' => ['   ', false],
            'hash' => ['5da7b5393d4f4445ac4db1ee8e95f567', false],
            'sample' => ['sample', false],
            'archive structure' => ['VIDEO_TS', false],
        ];
    }

    public function test_current_name_guard_recognizes_obfuscation_evidence(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertTrue($cleaner->currentNameLooksObfuscated(
            '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
            Category::MOVIE_OTHER
        ));
        $this->assertTrue($cleaner->currentNameLooksObfuscated('ordinary words', Category::OTHER_HASHED));
        $this->assertTrue($cleaner->currentNameLooksObfuscated('ordinary words', Category::MOVIE_OTHER, 'gibberish_random'));
        $this->assertFalse($cleaner->currentNameLooksObfuscated(
            'Some.Movie.2019.1080p.x264-GRP',
            Category::MOVIE_HD
        ));
    }

    public function test_less_informative_guard_rejects_same_signal_name_with_fewer_tokens(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertTrue($cleaner->isLessInformativeThan(
            'Breaking Bad S03 E06 BluRay - mkvCinemas',
            'Breaking Bad S03 E06 BluRay 1080p English DTS 5.1 x264 ESub - mkvCinemas',
        ));
    }

    #[DataProvider('mediaInfoDowngradeNames')]
    public function test_less_informative_guard_rejects_media_info_titles_ending_in_web_dl(
        string $currentName,
        string $candidate,
    ): void {
        $cleaner = new FileNameCleaner;

        $this->assertTrue($cleaner->isLessInformativeThan($candidate, $currentName));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mediaInfoDowngradeNames(): array
    {
        return [
            'When the Weather Is Fine' => [
                'When the Weather Is Fine S01E02 1080p NF WEB-DL [(Hindi + Korean) AAC 2.0] H.264 (DEV1L-DTiNS)',
                'When.the.Weather.Is.Fine.S01E02.1080p.NF.WEB-DL',
            ],
            '56 Days' => [
                '56 Days S01E05 1080p AMZN WEB-DL [(Hindi + Tamil + Telugu) DDP 5.1 + English DDPA 5.1] H.264 (FLUX-D...)',
                '56.Days.S01E05.1080p.AMZN.WEB-DL',
            ],
        ];
    }

    #[DataProvider('technicalSourceEndings')]
    public function test_technical_hyphens_do_not_create_scene_groups(string $source): void
    {
        $cleaner = new FileNameCleaner;
        $title = 'Show Name S01E01 1080p '.$source;

        $this->assertSame($title, $cleaner->formatSearchName($title));
        $this->assertFalse($cleaner->looksLikeSceneRelease($title));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function technicalSourceEndings(): array
    {
        return [
            'WEB-DL' => ['WEB-DL'],
            'WEB-RIP' => ['WEB-RIP'],
            'Blu-Ray' => ['Blu-Ray'],
        ];
    }

    public function test_real_group_after_technical_source_remains_scene_formatted(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertTrue($cleaner->looksLikeSceneRelease('Show.S01E01.1080p.WEB-DL-GROUP'));
        $this->assertSame(
            'Show.S01E01.1080p.WEB-DL-GROUP',
            $cleaner->formatSearchName('Show S01E01 1080p WEB-DL-GROUP'),
        );
    }

    public function test_dotted_group_suffix_remains_supported(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertTrue($cleaner->looksLikeSceneRelease('Show.S01E01.1080p.WEB-DL.GROUP'));
        $this->assertSame(
            'Show.S01E01.1080p.WEB-DL DTS 5.1.GROUP',
            $cleaner->preserveEvidenceTokens(
                'Show.S01E01.1080p.WEB-DL.GROUP',
                'Show.S01E01.1080p.WEB-DL.DTS.5.1.GROUP',
            ),
        );
    }

    public function test_strictly_more_informative_names_must_not_trade_away_existing_signals(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->isStrictlyMoreInformativeThan(
            'Movie 1080p-GRP',
            'Movie 2020-GRP',
        ));
        $this->assertTrue($cleaner->isStrictlyMoreInformativeThan(
            'Movie 2020 1080p-GRP',
            'Movie 2020 1080p',
        ));
    }

    public function test_preserving_evidence_keeps_distinct_tokens_from_the_same_group(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Show S01E01 1080p WEB-DL x264 GERMAN FRENCH',
            $cleaner->preserveEvidenceTokens(
                'Show S01E01',
                'Show.S01E01.1080p.WEB-DL.x264.GERMAN.FRENCH',
            ),
        );
    }

    public function test_preserving_evidence_keeps_audio_channels_language_and_subtitle_before_group(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Title BluRay 1080p x264 DTS 5.1 English ESub - grp',
            $cleaner->preserveEvidenceTokens(
                'Title BluRay - grp',
                'Title BluRay 1080p English DTS 5.1 x264 ESub - grp',
            ),
        );
    }

    public function test_preserving_evidence_places_tokens_before_a_compact_scene_group_suffix(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Title.1080p DTS 5.1-GRP',
            $cleaner->preserveEvidenceTokens(
                'Title.1080p-GRP',
                'Title.1080p.DTS.5.1-GRP',
            ),
        );
    }

    public function test_preserving_evidence_never_splits_a_terminal_web_dl_token(): void
    {
        $cleaner = new FileNameCleaner;

        $result = $cleaner->preserveEvidenceTokens(
            'When.the.Weather.Is.Fine.S01E02.1080p.NF.WEB-DL',
            'When the Weather Is Fine S01E02 1080p NF WEB-DL English AAC 2.0 H.264',
        );

        $this->assertSame(
            'When.the.Weather.Is.Fine.S01E02.1080p.NF.WEB-DL H.264 AAC 2.0 English',
            $result,
        );
        $this->assertSame($result, $cleaner->preserveEvidenceTokens($result, $result));
    }

    public function test_preserving_evidence_keeps_the_persisted_name_within_255_characters(): void
    {
        $cleaner = new FileNameCleaner;

        $result = $cleaner->preserveEvidenceTokens(
            str_repeat('A', 250),
            'Show.S01E01.1080p.WEB-DL',
        );

        $this->assertLessThanOrEqual(255, mb_strlen($result));
        $this->assertStringEndsWith(' 1080p WEB-DL', $result);
    }

    public function test_preserving_evidence_merges_languages_but_uses_the_highest_priority_technical_source(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertSame(
            'Show S01E01 GERMAN 1080p WEB-DL x264 FRENCH SPANISH',
            $cleaner->preserveEvidenceTokens(
                'Show S01E01 GERMAN',
                'Show.S01E01.1080p.WEB-DL.x264.FRENCH',
                'Show.S01E01.720p.HDTV.x265.SPANISH',
            ),
        );
    }

    public function test_preserving_evidence_bounds_an_exhaustive_evidence_suffix(): void
    {
        $cleaner = new FileNameCleaner;
        $allEvidence = implode('.', [
            '360p', '480p', '540p', '576p', '720p', '1080p', '1080i', '2160p', '4k', 'uhd',
            'ntsc', 'pal', 'dvdrip', 'webrip', 'web-dl', 'bluray', 'blu-ray', 'bdrip', 'brrip',
            'hdtv', 'pdtv', 'dsr', 'tvrip', 'satrip', 'dthrip', 'hdrip', 'remux', 'ts', 'cam', 'r5',
            'xvid', 'divx', 'x264', 'x265', 'hevc', 'h.264', 'h.265', 'avc', 'av1',
            'danish', 'deutsch', 'dutch', 'flemish', 'french', 'german', 'hebrew', 'italian', 'ita',
            'norwegian', 'spanish', 'swedish', 'swesub', 'nl-sub', 'multi', 'dual',
        ]);

        $result = $cleaner->preserveEvidenceTokens('Show S01E01', $allEvidence);

        $this->assertLessThanOrEqual(255, mb_strlen($result));
        $this->assertStringContainsString(' 360p ntsc xvid ', $result);
    }

    public function test_preservation_vocabulary_does_not_expand_candidate_plausibility(): void
    {
        $cleaner = new FileNameCleaner;

        $this->assertFalse($cleaner->isPlausibleReleaseTitle('Documentary Collection AV1'));
    }
}
