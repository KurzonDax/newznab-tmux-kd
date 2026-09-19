<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\PredbMatchSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PredbMatchSelectorTest extends TestCase
{
    public function test_it_prefers_the_best_matching_predb_hit_over_the_first_hit(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('The.Fisher.King.1991.1080p.BluRay.x264-GRP', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
            [
                'id' => 2,
                'title' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP',
                'filename' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP.mkv',
            ],
        ]);

        $this->assertNotNull($bestMatch);
        $this->assertSame(2, $bestMatch['id']);
    }

    public function test_it_rejects_numeric_only_queries(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('1', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    public function test_it_rejects_generic_dvd_structure_queries(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('VTS_01_1', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    public function test_a_cover_style_query_does_not_identify_a_release(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch('cover the fisher king', [
            [
                'id' => 1,
                'title' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF',
                'filename' => '1.vs.1.Suzu.Matsuoka.JAPANESE.XXX.720p.WEBRiP.MP4-MFPF.mp4',
            ],
            [
                'id' => 2,
                'title' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP',
                'filename' => 'The.Fisher.King.1991.1080p.BluRay.x264-GRP.mkv',
            ],
        ]);

        $this->assertNull($bestMatch);
    }

    #[DataProvider('releaseIdentificationCases')]
    public function test_a_file_name_must_identify_the_predb_release(string $query, string $title, ?int $expectedId): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch($query, [['id' => 9, 'title' => $title]]);

        if ($expectedId === null) {
            $this->assertNull($bestMatch);
        } else {
            $this->assertNotNull($bestMatch);
            $this->assertSame($expectedId, $bestMatch['id']);
        }
    }

    /**
     * @return array<string, array{string, string, int|null}>
     */
    public static function releaseIdentificationCases(): array
    {
        return [
            'generic instructions' => [
                'Instructions!',
                'Fetti_Mac_And_OsoSuckaK-Player_Instructions-WEB-2026-RAGEMP3',
                null,
            ],
            'short descriptive name' => [
                'Hot Stuff',
                'Hot.Stuff.1979.1080p.WEB.H264-OUTPOST31',
                null,
            ],
            'work title and year only' => [
                'Visible Release (2026)',
                'Visible.Release.2026.1080p.BluRay.x264-GRP',
                null,
            ],
            'group tag without enough identifying words' => [
                'The Grp Release',
                'Visible.Release.2026.German.DL.1080p.BluRay.x264-GRP',
                null,
            ],
            'full name without group' => [
                'Visible.Release.2026.1080p.BluRay.x264',
                'Visible.Release.2026.1080p.BluRay.x264-GRP',
                9,
            ],
            'scene abbreviation with leading group' => [
                'grp-visible.release-1080p',
                'Visible.Release.2026.German.DL.1080p.BluRay.x264-GRP',
                9,
            ],
        ];
    }

    public function test_it_rejects_unrelated_release_sharing_only_date_and_resolution(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch(
            'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p',
            [
                [
                    'id' => 1,
                    'title' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB',
                    'filename' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB.mp4',
                    'source' => 'srrdb',
                ],
                [
                    'id' => 2,
                    'title' => 'SomeOther.26.04.07.Random.Title.XXX.2160p.MP4-GRP',
                    'filename' => 'SomeOther.26.04.07.Random.Title.XXX.2160p.MP4-GRP.mp4',
                    'source' => 'srrdb',
                ],
            ]
        );

        $this->assertNull($bestMatch, 'Should reject all results when none share meaningful tokens with the query');
    }

    public function test_it_selects_correct_match_when_date_overlaps_with_unrelated_results(): void
    {
        $selector = new PredbMatchSelector;

        $bestMatch = $selector->selectBestMatch(
            'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p',
            [
                [
                    'id' => 1,
                    'title' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB',
                    'filename' => 'Private.26.04.07.Alice.Ross.And.Nata.Gold.XXX.2160p.MP4-WRB.mp4',
                    'source' => 'srrdb',
                ],
                [
                    'id' => 2,
                    'title' => 'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p-GRP',
                    'filename' => 'NubileFilms.Molly.Little.My.Little.Seductress.S50E7.26.04.07.2160p.mp4',
                    'source' => 'srrdb',
                ],
            ]
        );

        $this->assertNotNull($bestMatch);
        $this->assertSame(2, $bestMatch['id'], 'Should select the result that shares meaningful tokens with the query');
    }
}
