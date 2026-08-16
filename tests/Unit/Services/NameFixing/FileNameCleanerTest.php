<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\FileNameCleaner;
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
}
