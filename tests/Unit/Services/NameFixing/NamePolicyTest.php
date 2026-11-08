<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\NameCandidate;
use App\Services\NameFixing\NameDecision;
use App\Services\NameFixing\NamePolicy;
use App\Services\NameFixing\NameRejection;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\TrustedDonor;
use LogicException;
use PHPUnit\Framework\TestCase;

class NamePolicyTest extends TestCase
{
    private const string OBFUSCATED = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    private const string TITLE = 'Some.Movie.2024.1080p.BluRay.x264-GROUP';

    public function test_a_plausible_title_from_an_untrusted_source_is_accepted_untrusted(): void
    {
        $decision = (new NamePolicy)->decide($this->release(), $this->candidate(NameSource::FileName, self::TITLE));

        $this->assertTrue($decision->accepted());
        $this->assertSame(self::TITLE, $decision->title);
        $this->assertFalse($decision->skipsPlausibility);
        $this->assertSame(TrustedDonor::Untrusted, $decision->trust);
    }

    public function test_a_predb_id_trusts_any_source_but_a_descriptive_container_title(): void
    {
        $this->assertSame([true, TrustedDonor::Trusted], NamePolicy::trust(NameSource::FileName, 77));
        $this->assertSame([false, TrustedDonor::Untrusted], NamePolicy::trust(NameSource::MediaDescriptiveTitle, 77));
        $this->assertSame([false, TrustedDonor::Trusted], NamePolicy::trust(NameSource::FileNzbSplitWrapper, 0));
    }

    public function test_a_refused_recovery_identity_rejects_the_candidate(): void
    {
        $this->assertRejected(NameRejection::RecoveryRefused, (new NamePolicy)->decide(
            $this->release(), $this->candidate(NameSource::Par2File, self::TITLE), recoveryAllowed: false,
        ));
    }

    public function test_a_release_this_service_renamed_is_not_renamed_again(): void
    {
        $this->assertRejected(NameRejection::AlreadyRenamed, (new NamePolicy)->decide(
            $this->release(), $this->candidate(NameSource::Par2PreDbMatch, self::TITLE, 77), alreadyRenamed: true,
        ));
    }

    public function test_a_promotional_title_is_rejected_even_with_a_predb_id(): void
    {
        $this->assertRejected(NameRejection::Promotional, (new NamePolicy)->decide(
            $this->release(), $this->candidate(NameSource::Par2PreDbMatch, 'Downloaded from example.org', 77),
        ));
    }

    public function test_a_media_title_offered_outside_the_descriptive_fallback_is_rejected(): void
    {
        $this->assertRejected(NameRejection::DescriptiveMediaTitle, (new NamePolicy)->decide(
            $this->release(), $this->candidate(NameSource::MediaDescriptiveTitle, 'Example Studio - Extended Evening Feature (2026-01-11)'),
        ));
    }

    public function test_an_episode_title_does_not_name_a_release_holding_another_episode(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release(),
            $this->candidate(NameSource::Par2HashDonor, 'Show.S01E01.1080p.WEB-DL-GRP'),
            storedFiles: static fn (): array => ['Show.S01E01.1080p.WEB-DL-GRP.mkv' => 400, 'Show.S01E02.1080p.WEB-DL-GRP.mkv' => 400],
        );

        $this->assertRejected(NameRejection::EpisodeOfLargerRelease, $decision);
    }

    public function test_the_stored_files_are_read_only_for_an_episode_title(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release(),
            $this->candidate(NameSource::Par2HashDonor, self::TITLE),
            storedFiles: static fn (): array => throw new LogicException('files read'),
        );

        $this->assertTrue($decision->accepted());
    }

    public function test_an_implausible_title_needs_a_source_that_skips_plausibility(): void
    {
        $this->assertRejected(NameRejection::Implausible, (new NamePolicy)->decide(
            $this->release(), $this->candidate(NameSource::FileName, 'Some Movie Night'),
        ));
        $this->assertTrue((new NamePolicy)->decide($this->release(), $this->candidate(NameSource::Crc32Donor, 'Some Movie Night'))->accepted());
    }

    public function test_a_readable_name_is_not_replaced_by_a_poorer_one(): void
    {
        $this->assertRejected(NameRejection::LessInformative, (new NamePolicy)->decide(
            $this->release('The.Odd.Life.of.Timothy.Green.2012.NTSC.DVDR-SCREAM', Category::MOVIE_DVD), $this->candidate(NameSource::FileName, 'Tolotg-Scream'),
        ));
    }

    public function test_the_current_name_is_not_a_rename(): void
    {
        $this->assertRejected(NameRejection::SameName, (new NamePolicy)->decide(
            $this->release(self::TITLE, Category::MOVIE_HD), $this->candidate(NameSource::Crc32Donor, strtolower(self::TITLE)),
        ));
    }

    public function test_a_groups_own_file_name_does_not_replace_that_groups_name(): void
    {
        $this->assertRejected(NameRejection::OwnGroupFileName, (new NamePolicy)->decide(
            $this->release('All.Saints.S10E02.DVDRip.XviD-aAF'),
            $this->candidate(NameSource::FileName, 'aaf-all.saints.s10e02.the.hardest.word'),
        ));
    }

    public function test_a_donor_does_not_spread_a_groups_own_file_name(): void
    {
        $this->assertRejected(NameRejection::OwnGroupFileName, (new NamePolicy)->decide(
            $this->release('All.Saints.S10E02.DVDRip.XviD-aAF'),
            $this->candidate(NameSource::Crc32Donor, 'Aaf-All.Saints.S10e02.The.Hardest DVDRip XviD.Word'),
        ));
    }

    public function test_a_numeric_group_tag_counts_as_the_groups_tag(): void
    {
        $this->assertRejected(NameRejection::OwnGroupFileName, (new NamePolicy)->decide(
            $this->release('Bundesliga.2010.Week.7.Highlights.WS.PDTV.XviD-433'),
            $this->candidate(NameSource::FileName, '433-bl2010w7hl.ws.pdtv.xvid'),
        ));
    }

    public function test_a_spaced_hyphen_group_tag_counts_as_the_groups_tag(): void
    {
        $this->assertRejected(NameRejection::OwnGroupFileName, (new NamePolicy)->decide(
            $this->release('Title BluRay - grp'),
            $this->candidate(NameSource::FileName, 'grp-title.bluray.1080p'),
        ));
    }

    public function test_a_par2_file_name_starting_with_the_groups_tag_and_a_space_is_rejected(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release('About.Men.And.Women.S01E04.DVDRip.XviD-aAF'),
            $this->candidate(NameSource::Par2File, 'aaf-amaws01e04.vol63+06.par2'),
        );

        $this->assertRejected(NameRejection::OwnGroupFileName, $decision);
        $this->assertSame('Aaf DVDRip XviD-amaws01e04', $decision->title);
    }

    public function test_a_predb_backed_file_name_starting_with_the_groups_tag_is_not_refused_as_its_own_file_name(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release('All.Saints.S10E02.DVDRip.XviD-aAF'),
            $this->candidate(NameSource::FilePreDbFilenameMatch, 'aaf-all.saints.s10e02', 5),
        );

        $this->assertNotSame(NameRejection::OwnGroupFileName, $decision->rejection);
    }

    public function test_a_title_ending_in_the_same_group_tag_is_not_refused_as_its_own_file_name(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release('Show.S01E01.720p.HDTV.x264-GRP'),
            $this->candidate(NameSource::FileName, 'Show.S01E01.1080p.WEB-DL.x264-GRP'),
        );

        $this->assertNotSame(NameRejection::OwnGroupFileName, $decision->rejection);
    }

    public function test_an_unguarded_source_is_accepted_as_its_writer_built_it(): void
    {
        $decision = (new NamePolicy)->decide(
            $this->release(),
            $this->candidate(NameSource::AudioTags, 'Downloaded from example.org'),
            recoveryAllowed: false,
            alreadyRenamed: true,
        );

        $this->assertTrue($decision->accepted());
        $this->assertSame('Downloaded from example.org', $decision->title);
        $this->assertSame(TrustedDonor::Trusted, $decision->trust);
    }

    private function release(string $searchName = self::OBFUSCATED, int $category = Category::OTHER_HASHED): object
    {
        return (object) [
            'releases_id' => 1,
            'name' => $searchName.' yEnc',
            'searchname' => $searchName,
            'categories_id' => $category,
            'relsize' => 1000,
        ];
    }

    private function candidate(NameSource $source, string $title, int $preDbId = 0): NameCandidate
    {
        return new NameCandidate(1, $title, $source, 'label', $preDbId);
    }

    private function assertRejected(NameRejection $rejection, NameDecision $decision): void
    {
        $this->assertFalse($decision->accepted());
        $this->assertSame($rejection, $decision->rejection);
    }
}
