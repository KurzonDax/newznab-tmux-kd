<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdditionalWorkPlannerTest extends TestCase
{
    use CreatesProcessingConfiguration;

    #[Test]
    public function it_builds_one_ordered_plan_for_direct_and_archive_candidates(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
            'processAudioInfo' => true,
        ]));

        $plan = $planner->plan([
            ['title' => 'release.part02.rar', 'segments' => ['<part-2>', '<shared>']],
            ['title' => 'release.sample.mkv" yEnc', 'segments' => ['<sample>', '<sample-2>']],
            ['title' => 'release.main-video.mkv" yEnc', 'segments' => ['<main-video>', '<main-video-2>']],
            ['title' => '"cover.jpg" yEnc', 'segments' => ['<cover>']],
            ['title' => '"track.FLAC" yEnc', 'segments' => ['<audio>']],
            ['title' => 'release.part01.rar', 'segments' => ['<part-1>', '<shared>', '<part-1>']],
        ], 'alt.binaries.test');

        $this->assertSame(['<sample>', '<sample-2>'], $plan->sampleMessageIds);
        $this->assertSame(['<cover>'], $plan->jpgMessageIds);
        $this->assertSame(['<main-video>', '<main-video-2>'], $plan->mediaInfoMessageIds);
        $this->assertSame('<audio>', $plan->audioInfoMessageId);
        $this->assertSame('FLAC', $plan->audioInfoExtension);
        $this->assertTrue($plan->hasCompressedFile());
        $this->assertSame(
            ['release.part02.rar', 'release.part01.rar'],
            array_map(static fn (ArchiveCandidate $candidate): string => $candidate->title, $plan->archiveCandidates),
        );
        $this->assertFalse($plan->archiveCandidates[0]->likelyFirstVolume);
        $this->assertTrue($plan->archiveCandidates[1]->likelyFirstVolume);
        $this->assertSame(
            ['release.part01.rar', 'release.part02.rar'],
            array_map(static fn (ArchiveCandidate $candidate): string => $candidate->title, $plan->prioritizedArchiveCandidates()),
        );
        $this->assertSame(
            ['release.part01.rar', 'release.part02.rar'],
            array_map(static fn (ArchiveCandidate $candidate): string => $candidate->title, $plan->orderedArchiveCandidates(true)),
        );
        $this->assertSame(2, $plan->duplicateMessageIdCount);
        $this->assertSame([], $plan->unsupportedReasons);
    }

    #[Test]
    public function it_reports_book_floods_and_releases_without_supported_candidates(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig());
        $contents = array_fill(0, 81, [
            'title' => 'book.epub',
            'segments' => ['<book>'],
        ]);

        $plan = $planner->plan($contents, 'alt.binaries.books');

        $this->assertSame(81, $plan->bookFileCount);
        $this->assertTrue($plan->bookFlood);
        $this->assertSame(['book-flood', 'no-supported-candidates'], $plan->unsupportedReasons);
    }

    #[Test]
    public function it_selects_jpeg_png_and_webp_image_candidates(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig(['processJPGSample' => true]));

        foreach (['cover.jpg', 'cover.png', 'cover.webp'] as $index => $filename) {
            $messageId = '<image-'.$index.'>';
            $plan = $planner->plan([
                ['title' => '"'.$filename.'" yEnc', 'segments' => [$messageId]],
            ], 'alt.binaries.test');

            $this->assertSame([$messageId], $plan->jpgMessageIds, $filename.' should be selected');
        }
    }

    #[Test]
    public function it_keeps_archive_volumes_out_of_direct_media_candidates(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
            'processAudioInfo' => true,
        ]));

        $plan = $planner->plan([
            ['title' => 'example.sample.mkv.part001.rar" yEnc', 'segments' => ['<archive-video>']],
            ['title' => 'cover.jpg.part001.rar" yEnc', 'segments' => ['<archive-image>']],
            ['title' => 'track.flac.part001.rar" yEnc', 'segments' => ['<archive-audio>']],
            ['title' => 'book.epub.part001.rar" yEnc', 'segments' => ['<archive-book>']],
            ['title' => 'example.sample.mkv" yEnc', 'segments' => ['<sample>']],
            ['title' => 'example.mp4" yEnc', 'segments' => ['<video>']],
            ['title' => 'cover.jpg" yEnc', 'segments' => ['<image>']],
            ['title' => 'track.flac" yEnc', 'segments' => ['<audio>']],
        ], 'alt.binaries.test');

        $this->assertSame(['<sample>'], $plan->sampleMessageIds);
        $this->assertSame(['<image>'], $plan->jpgMessageIds);
        $this->assertSame(['<video>'], $plan->mediaInfoMessageIds);
        $this->assertSame('<audio>', $plan->audioInfoMessageId);
        $this->assertSame('flac', $plan->audioInfoExtension);
        $this->assertSame(
            [
                'example.sample.mkv.part001.rar" yEnc',
                'cover.jpg.part001.rar" yEnc',
                'track.flac.part001.rar" yEnc',
                'book.epub.part001.rar" yEnc',
            ],
            array_map(static fn (ArchiveCandidate $candidate): string => $candidate->title, $plan->archiveCandidates),
        );
        $this->assertSame(1, $plan->bookFileCount);
    }

    #[Test]
    public function it_selects_terminal_media_when_earlier_subject_tokens_look_archived(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
            'processAudioInfo' => true,
        ]));

        $plan = $planner->plan([
            ['title' => '"release.rar" - "clip.sample.mkv" yEnc', 'segments' => ['<sample>']],
            ['title' => '"release.rar" - "clip.mp4" yEnc', 'segments' => ['<video>']],
            ['title' => '"release.rar" - "cover.jpg" yEnc', 'segments' => ['<image>']],
            ['title' => '"release.rar" - "track.flac" yEnc', 'segments' => ['<audio>']],
        ], 'alt.binaries.test');

        $this->assertSame(['<sample>'], $plan->sampleMessageIds);
        $this->assertSame(['<image>'], $plan->jpgMessageIds);
        $this->assertSame(['<video>'], $plan->mediaInfoMessageIds);
        $this->assertSame('<audio>', $plan->audioInfoMessageId);
    }

    #[Test]
    public function it_keeps_a_usable_last_volume_when_the_first_volume_is_missing(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig());
        $plan = $planner->plan([
            ['title' => 'release.part99.rar', 'segments' => ['<last-volume>']],
        ], 'alt.binaries.test');

        $this->assertFalse($plan->archiveCandidates[0]->likelyFirstVolume);
        $this->assertSame(['<last-volume>'], $plan->orderedArchiveCandidates(true)[0]->messageIds);
    }

    #[Test]
    public function it_selects_the_largest_unknown_payload_then_small_low_segment_candidates(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig([
            'payloadSniffMaxCandidates' => 3,
            'payloadSniffByteBudget' => 900,
            'payloadSniffSmallSegmentLimit' => 4,
        ]));

        $plan = $planner->plan([
            ['title' => 'small.bin', 'segments' => ['<small>'], 'size' => 100, 'partsactual' => 1],
            ['title' => 'many.bin', 'segments' => ['<many-1>', '<many-2>', '<many-3>', '<many-4>', '<many-5>'], 'size' => 500, 'partsactual' => 5],
            ['title' => 'largest', 'segments' => ['<large-1>', '<large-2>'], 'size' => 1200, 'partsactual' => 2],
            ['title' => 'medium.file', 'segments' => ['<medium-1>', '<medium-2>'], 'size' => 400, 'partsactual' => 2],
        ], 'alt.binaries.test');

        $this->assertSame(
            ['largest', 'small.bin', 'medium.file'],
            array_map(static fn (UnknownPayloadCandidate $candidate): string => $candidate->title, $plan->unknownPayloadCandidates),
        );
        $this->assertSame(['<large-1>', '<small>', '<medium-1>'], array_map(
            static fn (UnknownPayloadCandidate $candidate): string => $candidate->firstMessageId,
            $plan->unknownPayloadCandidates,
        ));
        $this->assertSame([], $plan->unsupportedReasons);
    }

    #[Test]
    public function it_caps_unknown_payloads_by_first_segment_byte_budget(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig([
            'payloadSniffMaxCandidates' => 3,
            'payloadSniffByteBudget' => 250,
        ]));

        $plan = $planner->plan([
            ['title' => 'largest.bin', 'segments' => ['<largest-1>', '<largest-2>'], 'size' => 400, 'partsactual' => 2],
            ['title' => 'small.bin', 'segments' => ['<small>'], 'size' => 100, 'partsactual' => 1],
        ], 'alt.binaries.test');

        $this->assertSame(['largest.bin'], array_map(
            static fn (UnknownPayloadCandidate $candidate): string => $candidate->title,
            $plan->unknownPayloadCandidates,
        ));
    }

    #[Test]
    public function it_only_sniffs_unknown_payloads_when_no_normal_candidate_exists(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig());

        $plan = $planner->plan([
            ['title' => 'opaque.bin', 'segments' => ['<opaque>'], 'size' => 100, 'partsactual' => 1],
            ['title' => 'release.part01.rar', 'segments' => ['<archive>'], 'size' => 200, 'partsactual' => 1],
        ], 'alt.binaries.test');

        $this->assertSame([], $plan->unknownPayloadCandidates);
        $this->assertSame(['release.part01.rar'], array_map(
            static fn (ArchiveCandidate $candidate): string => $candidate->title,
            $plan->archiveCandidates,
        ));
    }

    #[Test]
    public function it_keeps_normal_rar_and_par2_subject_handling_unchanged(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig());

        $plan = $planner->plan([
            ['title' => 'release.part01.rar', 'segments' => ['<archive>'], 'size' => 200, 'partsactual' => 1],
            ['title' => 'release.par2', 'segments' => ['<par2>'], 'size' => 100, 'partsactual' => 1],
        ], 'alt.binaries.test');

        $this->assertSame([], $plan->unknownPayloadCandidates);
        $this->assertSame(['release.part01.rar'], array_map(
            static fn (ArchiveCandidate $candidate): string => $candidate->title,
            $plan->archiveCandidates,
        ));
        $this->assertSame([], $plan->unsupportedReasons);
    }

    #[Test]
    public function it_does_not_treat_meaningful_unsupported_extensions_as_unknown_payloads(): void
    {
        $planner = new AdditionalWorkPlanner($this->makeConfig());

        $plan = $planner->plan([
            ['title' => 'book.epub', 'segments' => ['<book>'], 'size' => 100, 'partsactual' => 1],
        ], 'alt.binaries.books');

        $this->assertSame([], $plan->unknownPayloadCandidates);
        $this->assertSame(['no-supported-candidates'], $plan->unsupportedReasons);
    }
}
