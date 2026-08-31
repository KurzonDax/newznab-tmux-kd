<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\RegexService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CollectionsCleaningServiceTest extends TestCase
{
    public function test_dutch_vd_counters_share_one_non_music_collection_name(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            'Purple Yoda Posts: The Complete Les Claypool Discography.part16.rar <17 vd 34> yEnc',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            'Purple Yoda Posts: The Complete Les Claypool Discography.part17.rar <18 vd 34> yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame($first['name'], $second['name']);
        $this->assertStringNotContainsString('vd 34', $first['name']);
    }

    public function test_dutch_vd_counters_share_one_music_collection_name(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            'Purple Yoda Posts: The Complete Les Claypool Discography.part16.rar <17 vd 34> yEnc',
            'alt.binaries.sounds.lossless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            'Purple Yoda Posts: The Complete Les Claypool Discography.part17.rar <18 vd 34> yEnc',
            'alt.binaries.sounds.lossless',
        );

        $this->assertSame($first['name'], $second['name']);
        $this->assertStringNotContainsString('vd 34', $first['name']);
    }

    public function test_dutch_van_counters_share_one_collection_name(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            'Foo.part01.rar <1 van 20> yEnc',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            'Foo.part02.rar <2 van 20> yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame($first['name'], $second['name']);
        $this->assertStringNotContainsString('van 20', $first['name']);
    }

    public function test_dutch_counters_are_case_insensitive_and_accept_underscore_separators(): void
    {
        $first = $this->cleaner()->collectionsCleaner(
            'Foo.part01.rar <1_VD_20> yEnc',
            'alt.binaries.boneless',
        );
        $second = $this->cleaner()->collectionsCleaner(
            'Foo.part02.rar <2_vd_20> yEnc',
            'alt.binaries.boneless',
        );

        $this->assertSame($first['name'], $second['name']);
        $this->assertStringNotContainsString('VD_20', $first['name']);
    }

    #[DataProvider('cleanerPathGroups')]
    public function test_dutch_counter_at_end_of_subject_is_removed(string $groupName): void
    {
        $first = $this->cleaner()->collectionsCleaner('Foo.part01.rar 1 vd 20', $groupName);
        $second = $this->cleaner()->collectionsCleaner('Foo.part02.rar 2 vd 20', $groupName);

        $this->assertSame($first['name'], $second['name']);
        $this->assertStringNotContainsString('vd 20', $first['name']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cleanerPathGroups(): array
    {
        return [
            'generic cleaner' => ['alt.binaries.boneless'],
            'music cleaner' => ['alt.binaries.sounds.lossless'],
        ];
    }

    #[DataProvider('conventionalCounterSubjects')]
    public function test_conventional_counter_subjects_keep_their_existing_cleaned_names(
        string $subject,
        string $expectedName,
    ): void {
        $cleaned = $this->cleaner()->collectionsCleaner($subject, 'alt.binaries.tv');

        $this->assertSame($expectedName, $cleaned['name']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function conventionalCounterSubjects(): array
    {
        return [
            'bracketed counter with quoted filename' => [
                '[02/80] - "The.West.Wing.S06E02.1080p.BluRay.x264.mkv.part01.rar" yEnc',
                'The.West.Wing.S06E02.1080p.BluRay.x yEnc',
            ],
            'parenthesized counter' => ['My Release (01/20) yEnc', 'My Release yEnc'],
            'of counter' => ['My Release 01 of 20 yEnc', 'My Release yEnc'],
        ];
    }

    #[DataProvider('legitimateTitleSubjects')]
    public function test_non_counter_words_and_angle_brackets_remain_in_titles(
        string $subject,
        string $expectedName,
    ): void {
        $cleaned = $this->cleaner()->collectionsCleaner($subject, 'alt.binaries.tv');

        $this->assertSame($expectedName, $cleaned['name']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function legitimateTitleSubjects(): array
    {
        return [
            'angle-bracketed text' => [
                'Archive <Group Name>.part01.rar yEnc',
                'Archive <Group Name> yEnc',
            ],
            'of in title' => ['Best of Queen [01/34] yEnc', 'Best of Queen yEnc'],
            'vd in title' => ['Best vd Collection [01/34] yEnc', 'Best vd Collection yEnc'],
            'van in title' => ['Best van Halen [01/34] yEnc', 'Best van Halen yEnc'],
        ];
    }

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
