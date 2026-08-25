<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Enums\NzbParseFailure;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use PHPUnit\Framework\TestCase;

class NzbContentParserTest extends TestCase
{
    use CreatesProcessingConfiguration;

    public function test_it_classifies_a_missing_path_as_storage_unavailable_when_no_nzb_root_is_readable(): void
    {
        $nzb = $this->createMock(NzbService::class);
        $nzb->expects($this->once())->method('nzbPath')->with('guid-1')->willReturn(false);
        $nzb->expects($this->once())->method('hasReadableNzbStorage')->willReturn(false);

        $result = (new NzbContentParser($nzb, $this->createStub(NzbParserService::class)))
            ->parseNzb('guid-1');

        $this->assertSame(NzbParseFailure::StorageUnavailable, $result['failure']);
        $this->assertSame([], $result['contents']);
        $this->assertStringContainsString('storage', strtolower((string) $result['error']));
    }

    public function test_it_classifies_an_absent_nzb_as_missing_when_storage_is_readable(): void
    {
        $nzb = $this->createMock(NzbService::class);
        $nzb->expects($this->once())->method('nzbPath')->with('guid-1')->willReturn(false);
        $nzb->expects($this->once())->method('hasReadableNzbStorage')->willReturn(true);

        $result = (new NzbContentParser($nzb, $this->createStub(NzbParserService::class)))
            ->parseNzb('guid-1');

        $this->assertSame(NzbParseFailure::Missing, $result['failure']);
        $this->assertSame([], $result['contents']);
        $this->assertStringContainsString('not found', strtolower((string) $result['error']));
    }

    public function test_it_selects_configured_segments_from_a_bare_main_video_for_media_info(): void
    {
        $parser = $this->makeParser();
        $config = $this->makeConfig([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'segmentsToDownload' => 3,
        ]);

        $result = $parser->extractMessageIDs([
            [
                'title' => 'Example.Show.S01E01.1080p.WEB-DL.mkv" yEnc (1/4)',
                'segments' => ['main-1', 'main-2', 'main-3', 'main-4'],
            ],
        ], 'alt.binaries.tv', $config);

        $this->assertSame(['main-1', 'main-2', 'main-3'], $result['mediaInfoMessageIDs']);
        $this->assertSame([], $result['sampleMessageIDs']);
    }

    public function test_it_keeps_the_explicit_sample_for_the_sample_branch_and_main_video_for_media_info(): void
    {
        $parser = $this->makeParser();
        $config = $this->makeConfig([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'segmentsToDownload' => 2,
        ]);

        $result = $parser->extractMessageIDs([
            [
                'title' => 'Example.Show.S01E01.sample.mkv" yEnc (1/3)',
                'segments' => ['sample-1', 'sample-2', 'sample-3'],
            ],
            [
                'title' => 'Example.Show.S01E01.1080p.WEB-DL.mkv" yEnc (1/3)',
                'segments' => ['main-1', 'main-2', 'main-3'],
            ],
        ], 'alt.binaries.tv', $config);

        $this->assertSame(['sample-1', 'sample-2'], $result['sampleMessageIDs']);
        $this->assertSame(['main-1', 'main-2'], $result['mediaInfoMessageIDs']);
    }

    public function test_it_does_not_treat_sample_in_the_release_title_as_a_sample_file(): void
    {
        $parser = $this->makeParser();
        $config = $this->makeConfig([
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'segmentsToDownload' => 2,
        ]);

        $result = $parser->extractMessageIDs([
            [
                'title' => 'The.Sample.2026.1080p.WEB-DL.mkv" yEnc (1/3)',
                'segments' => ['main-1', 'main-2', 'main-3'],
            ],
        ], 'alt.binaries.movies', $config);

        $this->assertSame([], $result['sampleMessageIDs']);
        $this->assertSame(['main-1', 'main-2'], $result['mediaInfoMessageIDs']);
    }

    public function test_it_keeps_archive_volumes_out_of_direct_media_candidates(): void
    {
        $parser = $this->makeParser();
        $config = $this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
        ]);

        $result = $parser->extractMessageIDs([
            ['title' => 'example.sample.mkv.part001.rar" yEnc', 'segments' => ['<archive-video>']],
            ['title' => 'cover.jpg.part001.rar" yEnc', 'segments' => ['<archive-image>']],
            ['title' => 'track.flac.part001.rar" yEnc', 'segments' => ['<archive-audio>']],
            ['title' => 'book.epub.part001.rar" yEnc', 'segments' => ['<archive-book>']],
            ['title' => 'example.sample.mkv" yEnc', 'segments' => ['<sample>']],
            ['title' => 'example.mp4" yEnc', 'segments' => ['<video>']],
            ['title' => 'cover.jpg" yEnc', 'segments' => ['<image>']],
            ['title' => 'track.flac" yEnc', 'segments' => ['<audio>']],
        ], 'alt.binaries.test', $config);

        $this->assertTrue($result['hasCompressedFile']);
        $this->assertSame(['<sample>'], $result['sampleMessageIDs']);
        $this->assertSame(['<image>'], $result['jpgMessageIDs']);
        $this->assertSame(['<video>'], $result['mediaInfoMessageIDs']);
        $this->assertSame(1, $result['bookFileCount']);
    }

    public function test_it_selects_terminal_media_when_earlier_subject_tokens_look_archived(): void
    {
        $parser = $this->makeParser();
        $config = $this->makeConfig([
            'processThumbnails' => true,
            'processJPGSample' => true,
            'processMediaInfo' => true,
        ]);

        $result = $parser->extractMessageIDs([
            ['title' => '"release.rar" - "clip.sample.mkv" yEnc', 'segments' => ['<sample>']],
            ['title' => '"release.rar" - "clip.mp4" yEnc', 'segments' => ['<video>']],
            ['title' => '"release.rar" - "cover.jpg" yEnc', 'segments' => ['<image>']],
            ['title' => '"release.rar" - "track.flac" yEnc', 'segments' => ['<audio>']],
        ], 'alt.binaries.test', $config);

        $this->assertSame(['<sample>'], $result['sampleMessageIDs']);
        $this->assertSame(['<image>'], $result['jpgMessageIDs']);
        $this->assertSame(['<video>'], $result['mediaInfoMessageIDs']);
    }

    private function makeParser(): NzbContentParser
    {
        return new NzbContentParser(
            $this->createStub(NzbService::class),
            $this->createStub(NzbParserService::class),
        );
    }
}
