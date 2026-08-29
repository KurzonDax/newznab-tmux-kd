<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\RegexService;
use PHPUnit\Framework\TestCase;

class CollectionsCleaningServiceTest extends TestCase
{
    public function test_named_set_files_share_one_cleaned_name(): void
    {
        $subjects = [
            '[1/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst" yEnc (13/17) 11643018',
            '"1787977202_nicovideo_jp_watch_sm23010895.tar.zst.par2" yEnc (1/1) 760',
            '[2/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol00+01.par2" yEnc (1/2) 800828',
            '[3/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol01+02.par2" yEnc (2/3) 1601656',
            '[4/4] - "1787977202_nicovideo_jp_watch_sm23010895.tar.zst.vol07+08.par2" yEnc (2/9) 6411464',
        ];

        $cleanedNames = array_map(
            fn (string $subject): string => $this->cleaner()->collectionsCleaner($subject, 'alt.binaries.boneless')['name'],
            $subjects,
        );

        $this->assertCount(1, array_unique($cleanedNames));
    }

    public function test_named_sets_with_different_basenames_remain_distinct(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            '[1/6] - "1787977190_nicovideo_jp_watch_sm23010845.tar.zst" yEnc (1/181) 129477179',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            '[5/6] - "1787976714_nicovideo_jp_watch_sm22093081.tar.zst.vol07+08.par2" yEnc (2/9) 6411464',
            'alt.binaries.boneless',
        );

        $this->assertNotSame($first['name'], $second['name']);
    }

    public function test_prefix_token_set_ignores_varying_quoted_filenames_and_file_counters(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            '82426524-n [02/20] - "4tR8Nx2KpQ7vLm3Wa9Bc6Df1Gh5Jk0Ys8Zu4Ei7Oo2Pa6Sd9Fg3Hj5Kl7Xc1Vb8Nq4Rw6Ty0" yEnc (1/193) 251658240',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            '82426524-n [33/33] - "9zY3Wx7Vu1Ts5Rq8Po2Nm6Lk0Ji4Hg7Fe3Dc9Ba5Xv1Cu8Bt6Ar2Qs4Ep0On7Mi3Lk9Jh5Gf1" yEnc (1/193) 251658240',
            'alt.binaries.boneless',
        );

        $this->assertSame('82426524-n', $first['name']);
        $this->assertSame($first['name'], $second['name']);
    }

    public function test_different_prefix_tokens_remain_distinct(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            '82426524-n [02/20] - "4tR8Nx2KpQ7vLm3Wa9Bc6Df1Gh5Jk0Ys8Zu4Ei7Oo2Pa6Sd9Fg3Hj5Kl7Xc1Vb8Nq4Rw6Ty0" yEnc (1/193) 251658240',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            '82426525-n [02/20] - "4tR8Nx2KpQ7vLm3Wa9Bc6Df1Gh5Jk0Ys8Zu4Ei7Oo2Pa6Sd9Fg3Hj5Kl7Xc1Vb8Nq4Rw6Ty0" yEnc (1/193) 251658240',
            'alt.binaries.boneless',
        );

        $this->assertNotSame($first['name'], $second['name']);
    }

    public function test_classic_multi_file_subject_keeps_grouping(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            '[02/80] - "The.West.Wing.S06E02.1080p.BluRay.x264.mkv.part01.rar" yEnc',
            'alt.binaries.tv',
        );
        $second = $this->cleaner()->collectionsCleaner(
            '[03/80] - "The.West.Wing.S06E02.1080p.BluRay.x264.mkv.part02.rar" yEnc',
            'alt.binaries.tv',
        );

        $this->assertSame($first['name'], $second['name']);
    }

    public function test_unrelated_single_file_posts_do_not_merge_when_byte_counts_are_removed(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            '"independent-alpha.iso" yEnc (1/1) 104857600',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            '"independent-beta.iso" yEnc (1/1) 104857600',
            'alt.binaries.boneless',
        );

        $this->assertNotSame($first['name'], $second['name']);
    }

    private function cleaner(): CollectionsCleaningService
    {
        return new class extends CollectionsCleaningService
        {
            public function __construct()
            {
                parent::__construct();

                $this->_regexes = new class extends RegexService
                {
                    public function tryRegex(string $subject, string $groupName): string
                    {
                        $this->matchedRegex = 0;

                        return '';
                    }
                };
            }
        };
    }
}
