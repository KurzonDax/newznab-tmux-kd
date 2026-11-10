<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\DTO\YencArticleMetadata;
use App\Services\MediaInfo\DTO\MediaInfoProbeContext;
use App\Services\MediaInfo\Enums\MediaInfoSourceCompleteness;
use App\Services\TempWorkspaceService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SevenZipObfuscatedSetTest extends TestCase
{
    use BuildsSevenZipPostings;
    use CreatesProcessingConfiguration;

    /** @var list<array<string, mixed>> */
    private array $addedFiles = [];

    /** @var list<array{path: string, bytes: string, context: ?MediaInfoProbeContext}> */
    private array $videos = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_search_finds_a_first_part_posted_last_and_inspects_the_set(): void
    {
        // Four parts of 8192 bytes (two articles) and a 2,960-byte last part
        // (one article); the first part is last in NZB order.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 8192, 4096, [1, 2, 3, 0]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 1, 'payloadSniffSmallSegmentLimit' => 1]);

        $sniffed = $this->requestedIds('payload-sniff');
        $splitSet = ['p1s0@fixture', 'p2s0@fixture', 'p0s0@fixture'];
        $this->assertSame($splitSet, array_values(array_intersect($sniffed, $splitSet)));
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
        $this->assertTrue($context->releaseHasPassword, 'A header-encrypted set with no NZB password is passworded.');
    }

    public function test_the_search_never_sniffs_a_file_with_fewer_segments(): void
    {
        $contents = $this->junkPosting([3, 3, 3, 2]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 1, 'payloadSniffSmallSegmentLimit' => 1]);

        $this->assertSame(['j0s0', 'j1s0', 'j2s0'], $this->requestedIds('payload-sniff'));
        $this->assertNull($context->passwordVerdict);
    }

    /** @return iterable<string, array{array<string, int>, list<int>, list<string>}> */
    public static function searchLimits(): iterable
    {
        yield 'more files than the cap' => [['payloadSplitSearchMaxFiles' => 2], [3, 3, 3], ['seven-zip-volume-search-cap']];
        yield 'more bytes than the budget' => [['payloadSplitSearchByteBudget' => 2999], [3, 3, 3], ['seven-zip-volume-search-cap']];
        yield 'files limit 0 turns it off' => [['payloadSplitSearchMaxFiles' => 0], [3, 3, 3], []];
        yield 'byte limit 0 turns it off' => [['payloadSplitSearchByteBudget' => 0], [3, 3, 3], []];
    }

    /**
     * @param  array<string, int>  $limits
     * @param  list<int>  $segments
     * @param  list<string>  $reasons
     */
    #[DataProvider('searchLimits')]
    public function test_the_search_limits_stop_it_before_any_sniff(array $limits, array $segments, array $reasons): void
    {
        $context = $this->runSniffing($this->junkPosting($segments), ['payloadSniffMaxCandidates' => 1, 'payloadSniffSmallSegmentLimit' => 1] + $limits);

        $this->assertSame(['j0s0'], $this->requestedIds('payload-sniff'), 'Only the loop sniffed.');
        $this->assertSame($reasons, array_values(array_intersect($context->unsupportedReasons(), ['seven-zip-volume-search-cap'])));
    }

    public function test_no_article_is_requested_twice_across_search_lookup_and_inspection(): void
    {
        // Six equal parts of 4096 bytes (four 1 KiB articles) and a 2,960-byte
        // last part (three articles); the first part is last in NZB order.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 4096, 1024, [1, 2, 3, 4, 5, 6, 0]);

        $context = $this->runSniffing($contents);

        $requested = $this->requestedIds();
        $this->assertSame(array_values(array_unique($requested)), $requested);
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
        $inspected = $this->requestedIds('inspection');
        $this->assertNotSame([], $inspected);
        $this->assertSame([], array_values(array_filter($inspected, static fn (string $id): bool => ! str_starts_with($id, 'p0s') && ! str_starts_with($id, 'p6s'))),
            'Inspection reads only the first and last parts.');
    }

    public function test_a_last_part_the_loop_already_sniffed_is_not_fetched_again(): void
    {
        // The last part (three articles) is a small candidate the loop sniffs.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 4096, 1024, [6, 1, 2, 3, 4, 5, 0]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 2, 'payloadSniffSmallSegmentLimit' => 3]);

        $sniffed = $this->requestedIds('payload-sniff');
        $this->assertSame('p6s0@fixture', $sniffed[1], 'The loop sniffed the last part.');
        $this->assertSame('p0s0@fixture', end($sniffed), 'The lookup sniffed nothing after the first part was found.');
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
    }

    public function test_a_last_part_the_search_already_sniffed_is_not_fetched_again(): void
    {
        // Every part is two articles, so the last part is a split-set file the
        // search sniffs before it reaches the first part.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 7168, 4096, [1, 3, 2, 0]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 1]);

        $this->assertSame(['p1s0@fixture', 'p3s0@fixture', 'p2s0@fixture', 'p0s0@fixture'], $this->requestedIds('payload-sniff'));
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
    }

    public function test_a_file_whose_sniff_failed_is_not_asked_for_again(): void
    {
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 8192, 4096, [1, 2, 3, 0]);
        $missing = $this->postedArticles['p3s0@fixture'];
        unset($this->postedArticles['p3s0@fixture']);
        $this->downloadHook = function (array $ids) use ($missing): void {
            if ($ids === ['p0s0@fixture']) {
                $this->postedArticles['p3s0@fixture'] = $missing;
            }
        };

        // The loop sniffs the small last part first, and that fetch fails.
        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 2]);

        $this->assertSame(1, array_count_values($this->requestedIds())['p3s0@fixture']);
        $this->assertSame(PasswordVerdict::Incomplete, $context->passwordVerdict);
        $this->assertContains('7z-inspection:missing-volume', $context->unsupportedReasons());
    }

    public function test_a_last_part_shorter_than_the_header_is_a_missing_range(): void
    {
        // 9160-byte parts leave a 56-byte last part; the header starts in part 3.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 9160, 4096, [1, 2, 3, 0]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 1, 'payloadSniffSmallSegmentLimit' => 1]);

        $this->assertSame(PasswordVerdict::Incomplete, $context->passwordVerdict);
        $this->assertContains('7z-inspection:missing-range', $context->unsupportedReasons());
        $this->assertSame([], array_values(array_filter($this->requestedIds('inspection'), static fn (string $id): bool => str_starts_with($id, 'p1s') || str_starts_with($id, 'p2s'))));
    }

    public function test_parts_all_of_one_size_are_a_missing_volume(): void
    {
        // 27,536 bytes in four parts of 6884: the last part's size tells nothing.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 6884, 4096, [1, 2, 3, 0]);

        $context = $this->runSniffing($contents, ['payloadSniffMaxCandidates' => 1, 'payloadSniffSmallSegmentLimit' => 1]);

        $this->assertSame(PasswordVerdict::Incomplete, $context->passwordVerdict);
        $this->assertContains('7z-inspection:missing-volume', $context->unsupportedReasons());
        $this->assertSame([], $this->requestedIds('inspection'));
    }

    /** @return iterable<string, array{string, ?string, int, string}> */
    public static function previewArchives(): iterable
    {
        // fixture, NZB password, part size, the segment the window starts at
        yield 'header-encrypted with the password' => ['obfuscated-encrypted', 'fixture-only-pass', 7168, 'p0s1@fixture'];
        yield 'no password' => ['obfuscated-plain', null, 6144, 'p0s1@fixture'];
        yield 'visible names with the password' => ['obfuscated-visible', 'fixture-only-pass', 6144, 'p0s1@fixture'];
    }

    #[DataProvider('previewArchives')]
    public function test_the_preview_is_the_video_head_extracted_from_the_window(string $fixture, ?string $password, int $partSize, string $windowStart): void
    {
        $this->requireSevenZip();
        $contents = $this->sevenZipPosting($this->sevenZipFixture($fixture), $partSize, 4096, [1, 2, 3, 0]);

        $context = $this->runSniffing($contents, ['sevenZipPath' => '/usr/bin/7z', 'segmentsToDownload' => 2], $password);

        $this->assertCount(1, $this->videos);
        $video = $this->videos[0];
        $this->assertSame('ftyp', substr($video['bytes'], 4, 4));
        $this->assertStringEndsWith('.mp4', $video['path']);
        $this->assertSame('c.mp4', $video['context']?->sourceFilename);
        $this->assertSame(MediaInfoSourceCompleteness::Partial, $video['context']?->sourceCompleteness);
        $extract = $this->extractCommand();
        $this->assertStringContainsString(' -spd ', $extract);
        $this->assertStringEndsWith('| head -c '.strlen($video['bytes']).' > '.escapeshellarg($video['path']), $extract);
        $this->assertSame($windowStart, $this->requestedIds('compressed')[0]);
        $this->assertNotContains('p0s0@fixture', $this->requestedIds('compressed'), 'The held first article is reused.');
        $this->assertFalse(File::isDirectory($context->tmpPath.'sevenzip'));
        $this->assertFalse(File::exists($video['path']), 'The fragment is deleted.');
        if ($fixture === 'obfuscated-encrypted') {
            $this->assertSame(['a.txt', 'b.pad', 'c.mp4'], array_column($this->addedFiles, 'name'));
            $this->assertSame([0, 0, 0], array_column($this->addedFiles, 'pass'));
            $this->assertFalse($context->releaseHasPassword);
        }
    }

    public function test_the_window_length_is_the_bytes_after_the_video_start(): void
    {
        $this->requireSevenZip();
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 7168, 4096, [1, 2, 3, 0]);

        $this->runSniffing($contents, ['sevenZipPath' => '/usr/bin/7z', 'segmentsToDownload' => 2], 'fixture-only-pass');

        // c.mp4's block starts at 32 + 48 + 6016 = 6096; part 1 ends at 7168.
        $this->assertSame(7168 - 6096, strlen($this->videos[0]['bytes']));
        $this->assertSame(substr($this->sevenZipSource('c.mp4'), 0, 1072), $this->videos[0]['bytes']);
    }

    public function test_an_entry_name_is_never_run_by_the_shell(): void
    {
        $this->requireSevenZip();
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-hostile'), 6144, 4096, [1, 2, 3, 0]);
        $cwd = (string) getcwd();

        $context = $this->runSniffing($contents, ['sevenZipPath' => '/usr/bin/7z', 'segmentsToDownload' => 2]);

        $this->assertCount(1, $this->videos);
        $this->assertSame('ftyp', substr($this->videos[0]['bytes'], 4, 4));
        $this->assertSame('a"b$(touch pwned).mp4', $this->videos[0]['context']?->sourceFilename);
        foreach ([$cwd.'/pwned', base_path('pwned'), $context->tmpPath.'pwned'] as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_a_video_that_starts_in_a_placeholder_part_gets_no_preview(): void
    {
        $this->requireSevenZip();
        // 4096-byte parts: c.mp4 starts at 6096, inside part 2, a placeholder.
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 4096, 1024, [1, 2, 3, 4, 5, 6, 0]);

        $context = $this->runSniffing($contents, ['sevenZipPath' => '/usr/bin/7z'], 'fixture-only-pass');

        $this->assertSame(['a.txt', 'b.pad', 'c.mp4'], array_column($this->addedFiles, 'name'));
        $this->assertSame([], $this->requestedIds('compressed'));
        $this->assertSame([], $this->videos);
        $this->assertFalse(File::isDirectory($context->tmpPath.'sevenzip'));
    }

    public function test_a_wrong_password_marks_the_release_passworded_and_fetches_nothing_more(): void
    {
        $this->requireSevenZip();
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 7168, 4096, [1, 2, 3, 0]);

        $context = $this->runSniffing($contents, ['sevenZipPath' => '/usr/bin/7z'], 'not-the-password');

        $this->assertTrue($context->nzbPasswordRejected);
        $this->assertTrue($context->releaseHasPassword);
        $this->assertSame([], $this->requestedIds('compressed'));
        $this->assertSame([], $this->videos);
    }

    /** @return iterable<string, array{string|false, ?int}> */
    public static function unavailableSevenZip(): iterable
    {
        yield 'no path' => [false, null];
        yield 'a path that does not exist' => ['/nonexistent/7z', null];
        yield 'a runner that exits 127' => ['/bin/sh', 127];
    }

    #[DataProvider('unavailableSevenZip')]
    public function test_without_seven_zip_the_release_keeps_todays_result(string|false $path, ?int $exitCode): void
    {
        $runner = $exitCode === null ? null : function (string $command) use ($exitCode): array {
            $this->commands[] = $command;

            return ['exitCode' => $exitCode, 'output' => ''];
        };
        foreach (['obfuscated-encrypted' => 'fixture-only-pass', 'obfuscated-plain' => null] as $fixture => $password) {
            $this->postedArticles = $this->requests = $this->addedFiles = $this->videos = [];
            $contents = $this->sevenZipPosting($this->sevenZipFixture($fixture), $fixture === 'obfuscated-plain' ? 6144 : 7168, 4096, [1, 2, 3, 0]);

            $context = $this->runSniffing($contents, ['sevenZipPath' => $path], $password, $runner);

            $this->assertNotSame(PasswordVerdict::Incomplete, $context->passwordVerdict, $fixture);
            $this->assertFalse($context->releaseHasPassword, $fixture);
            $this->assertSame([], $this->videos, $fixture);
            $this->assertSame([], $this->requestedIds('compressed'), $fixture);
            if ($fixture === 'obfuscated-encrypted') {
                $this->assertSame([], $this->addedFiles, 'No file rows come from a listing.');
            }
            $this->assertFalse(File::isDirectory($context->tmpPath.'sevenzip'));
        }
        if ($exitCode !== null) {
            // One listing per release (two releases ran); nothing follows its exit 127.
            $this->assertCount(2, $this->commands);
            $this->assertStringContainsString('" l -slt ', $this->commands[0]);
            $this->assertStringContainsString('" l -slt ', $this->commands[1]);
        }
    }

    public function test_a_failed_listing_is_incomplete(): void
    {
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 7168, 4096, [1, 2, 3, 0]);
        $runner = function (string $command): array {
            $this->commands[] = $command;

            return ['exitCode' => 2, 'output' => "ERROR: archive.7z.001\nHeaders Error\n"];
        };

        $context = $this->runSniffing($contents, ['sevenZipPath' => '/bin/sh'], 'fixture-only-pass', $runner);

        $this->assertSame(PasswordVerdict::Incomplete, $context->passwordVerdict);
        $this->assertContains('7z-inspection:seven-zip-list-failed', $context->unsupportedReasons());
        $this->assertFalse($context->releaseHasPassword);
        $this->assertStringContainsString(" l -slt -bse1 -p'fixture-only-pass' -- ", $this->commands[0]);
    }

    /**
     * Runs the processor's sniffing step, with its search, lookup, inspection
     * and preview, over an NZB.
     *
     * @param  list<array<string, mixed>>  $contents
     * @param  array<string, mixed>  $config
     * @param  (\Closure(string): array{exitCode: int, output: string})|null  $runner
     */
    private function runSniffing(array $contents, array $config = [], ?string $password = null, ?\Closure $runner = null): ReleaseProcessingContext
    {
        $config = $this->makeConfig($config + ['processPasswords' => true, 'payloadSniffSmallSegmentLimit' => 1]);
        $context = new ReleaseProcessingContext(new Release(['id' => 7, 'categories_id' => 2040, 'guid' => 'guid-7']));
        $context->nzbContents = $contents;
        $context->nzbPassword = $password;
        $context->tmpPath = $this->makeTempDirectory('nntmux-7z-set').'/';
        $context->workPlan = (new AdditionalWorkPlanner($config))->plan($contents, '', 7);

        $processor = $this->processor($config, $runner ?? $this->recordingRunner());
        (new \ReflectionMethod($processor, 'processUnknownPayloadCandidates'))->invoke($processor, $context);

        return $context;
    }

    /** @param \Closure(string): array{exitCode: int, output: string} $runner */
    private function processor(ProcessingConfiguration $config, \Closure $runner): ReleaseProcessor
    {
        $manager = Mockery::mock(ReleaseFileManager::class);
        $manager->shouldReceive('processReleaseNameFromRar')->andReturnFalse();
        $manager->shouldReceive('addFileInfo')->andReturnUsing(function (array $file): bool {
            $this->addedFiles[] = $file;

            return true;
        });
        $media = Mockery::mock(MediaExtractionService::class);
        $media->shouldReceive('processVideoFile')->andReturnUsing(function (string $path, ReleaseProcessingContext $context, string $tmpPath, ?MediaInfoProbeContext $probe): array {
            $this->videos[] = ['path' => $path, 'bytes' => (string) file_get_contents($path), 'context' => $probe];

            return ['sample' => false, 'video' => false, 'mediaInfo' => true];
        });
        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldIgnoreMissing();

        return new ReleaseProcessor(
            $config,
            Mockery::mock(NzbContentParser::class),
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config, processRunner: $runner),
            $media,
            $this->sevenZipDownloader(),
            $manager,
            Mockery::mock(ReleaseFilesArchiveFallback::class),
            Mockery::mock(TempWorkspaceService::class),
            $output,
        );
    }

    /**
     * Extensionless files of pseudo-random bytes, one per segment count.
     *
     * @param  list<int>  $segmentCounts
     * @return list<array<string, mixed>>
     */
    private function junkPosting(array $segmentCounts): array
    {
        $files = [];
        foreach ($segmentCounts as $file => $segments) {
            $ids = [];
            for ($segment = 0; $segment < $segments; $segment++) {
                $ids[] = $id = 'j'.$file.'s'.$segment;
                $data = hash('sha256', $id, true).str_repeat("\x5a", 968);
                $this->postedArticles[$id] = ['success' => true, 'data' => $data, 'groupUnavailable' => false, 'error' => null,
                    'crcFailures' => 0, 'crcFailed' => false,
                    'metadata' => new YencArticleMetadata(1000 * $segments, $segment + 1, $segments, 1000 * $segment, 1000)];
            }
            $files[] = ['title' => '"'.substr(hash('sha256', 'junk'.$file), 0, 12).'" yEnc', 'segments' => $ids,
                'partsactual' => $segments, 'size' => 1000 * $segments];
        }

        return $files;
    }

    private function extractCommand(): string
    {
        foreach ($this->commands as $command) {
            if (str_contains($command, '" e -so ')) {
                return $command;
            }
        }
        $this->fail('No extract command ran.');
    }

    /** The bytes the fixtures' MP4 entry holds, read back from the plain fixture. */
    private function sevenZipSource(string $entry): string
    {
        $plain = $this->sevenZipFixture('obfuscated-plain');

        // obfuscated-plain stores a.txt (40 bytes) and then c.mp4 (21,177 bytes) with Copy.
        return $entry === 'c.mp4' ? substr($plain, 72, 21177) : '';
    }
}
