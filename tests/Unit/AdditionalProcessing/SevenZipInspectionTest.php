<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\UnknownPayloadCandidate;
use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use App\Services\AdditionalProcessing\Enums\PayloadClassification;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\PayloadSniffer;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\SevenZip\ArchiveRanges;
use App\Services\AdditionalProcessing\SevenZip\InspectionBudget;
use App\Services\AdditionalProcessing\SevenZip\Inspector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\DTO\YencArticleMetadata;
use App\Services\TempWorkspaceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SevenZipInspectionTest extends TestCase
{
    use CreatesProcessingConfiguration;

    /** @return iterable<string, array{string, PasswordVerdict}> */
    public static function archives(): iterable
    {
        yield 'encrypted headers' => ['encrypted', PasswordVerdict::VerifiedEncrypted];
        yield 'visible encrypted data' => ['visible', PasswordVerdict::VerifiedEncrypted];
        yield 'unencrypted' => ['plain', PasswordVerdict::VerifiedUnencrypted];
    }

    #[DataProvider('archives')]
    public function test_standalone_and_split_archives_use_bounded_metadata_reads(string $name, PasswordVerdict $expected): void
    {
        $data = $this->fixture($name);
        foreach ([65536, strlen($data)] as $volumeSize) {
            [$volumes, $articles] = $this->posting($data, $volumeSize);
            $calls = [];
            $ranges = new ArchiveRanges($volumes, function (string $id) use (&$calls, $articles): array {
                $this->assertNotContains($id, $calls, 'Each article is fetched once.');
                $calls[] = $id;

                return $articles[$id];
            }, new InspectionBudget(32, 131072, 10));
            $result = (new Inspector)->inspect($ranges->read(...));
            $this->assertSame($expected, $result['verdict'], $result['reason']);
            $this->assertLessThan(count($articles), count($calls));
            $this->assertLessThan(strlen($data) / 2, array_sum(array_map(fn (string $id): int => strlen($articles[$id]['data']), $calls)));
            if ($name !== 'encrypted') {
                $this->assertSame('payload.bin', $result['files'][0]['name']);
                $this->assertSame(262144, $result['files'][0]['size']);
            }
        }
    }

    public function test_compressed_manifest_preserves_full_paths(): void
    {
        $data = $this->fixture('manifest');
        $result = (new Inspector)->inspect(static fn (int $offset, int $length): string => substr($data, $offset, $length));
        $this->assertSame(PasswordVerdict::VerifiedUnencrypted, $result['verdict'], $result['reason']);
        $this->assertContains('nested/Descriptive.Title.2026.mkv', array_column($result['files'], 'name'));
    }

    public function test_head_fragment_is_incomplete_through_archive_service(): void
    {
        $head = substr($this->fixture('encrypted'), 0, 65536);
        $service = new ArchiveExtractionService($this->makeConfig(['processPasswords' => true]));
        $result = $service->processCompressedData($head, new ReleaseProcessingContext(new Release), $this->makeTempDirectory());
        $this->assertSame(PasswordVerdict::Incomplete, $result['verdict']);
        $this->assertFalse($result['hasPassword']);
        $this->assertSame(PayloadClassification::SevenZip, (new PayloadSniffer)->classify($head)->classification);
    }

    public function test_disabled_inspection_keeps_the_existing_fragment_behavior(): void
    {
        $head = substr($this->fixture('encrypted'), 0, 65536);
        $service = new ArchiveExtractionService($this->makeConfig(['processPasswords' => false]));
        $result = $service->processCompressedData($head, new ReleaseProcessingContext(new Release), $this->makeTempDirectory().'/');
        $this->assertArrayNotHasKey('verdict', $result);
        $this->assertFalse($result['hasPassword']);
    }

    public function test_failures_never_establish_a_verdict(): void
    {
        $original = $this->fixture('plain');
        $cases = [];
        $cases['start crc'] = substr_replace($original, "\0\0\0\0", 8, 4);
        $cases['next crc'] = substr($original, 0, -1)."\xff";
        $cases['truncated'] = substr($original, 0, -2);
        $headerOffset = 32 + unpack('Poffset', substr($original, 12, 8))['offset'];
        $truncatedHeader = substr($original, $headerOffset, -2);
        $fields = substr($original, 12, 8).pack('P', strlen($truncatedHeader)).pack('V', crc32($truncatedHeader));
        $cases['valid CRC but truncated grammar'] = $this->replaceStart(substr($original, 0, -2), $fields);
        $encoded = $this->fixture('manifest');
        $cases['corrupt encoded header'] = substr_replace($encoded, chr(ord($encoded[50]) ^ 255), 50, 1);
        $cases['overflow'] = $this->replaceStart($original, str_repeat("\xff", 8).substr($original, 20, 12));
        $cases['oversized header'] = $this->replaceStart($original, substr($original, 12, 8).pack('P', 2097152).substr($original, 28, 4));
        $cases['offset outside archive'] = $this->replaceStart($original, pack('P', 999999).substr($original, 20, 12));
        foreach ($cases as $name => $data) {
            $result = (new Inspector)->inspect(static fn (int $offset, int $length): string => substr($data, $offset, $length));
            $this->assertSame(PasswordVerdict::Incomplete, $result['verdict'], $name);
        }
        [$volumes, $articles] = $this->posting($original, 65536);
        foreach (['missing-volume', 'missing-article', 'geometry', 'crc', 'articles', 'bytes', 'time'] as $failure) {
            $selected = $failure === 'missing-volume' ? array_slice($volumes, 0, -1) : $volumes;
            $ranges = new ArchiveRanges($selected, static function (string $id) use ($articles, $failure): array {
                $article = $articles[$id];
                if ($failure === 'missing-article' && str_starts_with($id, 'v4')) {
                    return ['success' => false];
                }
                if ($failure === 'geometry') {
                    $article['metadata'] = null;
                }
                if ($failure === 'crc') {
                    $article['crcFailed'] = true;
                }

                return $article;
            }, new InspectionBudget($failure === 'articles' ? 1 : 32, $failure === 'bytes' ? 10 : 131072, $failure === 'time' ? 0 : 10));
            $this->assertSame(PasswordVerdict::Incomplete, (new Inspector)->inspect($ranges->read(...))['verdict'], $failure);
        }
    }

    public function test_numeric_volume_order_and_missing_volume_are_explicit(): void
    {
        [$volumes] = $this->posting($this->fixture('plain'), 65536);
        $plan = new AdditionalWorkPlan(archiveCandidates: array_reverse($volumes));
        $this->assertSame($volumes, $plan->sevenZipVolumes($volumes[0]->title));
        unset($volumes[2]);
        $this->assertSame([], (new AdditionalWorkPlan(archiveCandidates: array_values($volumes)))->sevenZipVolumes($volumes[0]->title));
    }

    #[DataProvider('archives')]
    public function test_metadata_crosses_article_and_volume_boundaries(string $name, PasswordVerdict $expected): void
    {
        $data = $this->fixture($name);
        $next = 32 + unpack('Poffset', substr($data, 12, 8))['offset'];
        foreach ([[intdiv($next + 10, 4), 16384], [strlen($data), intdiv($next + 10, 16)]] as [$volumeSize, $partSize]) {
            [$volumes, $articles] = $this->posting($data, $volumeSize, $partSize);
            $ranges = new ArchiveRanges($volumes, static fn (string $id): array => $articles[$id], new InspectionBudget(32, 200000, 10));
            $result = (new Inspector)->inspect($ranges->read(...));
            $this->assertSame($expected, $result['verdict'], $result['reason']);
        }
    }

    public function test_conflicting_adjacent_offsets_are_incomplete(): void
    {
        $data = $this->fixture('plain');
        [$volumes, $articles] = $this->posting($data, strlen($data));
        $lastId = array_key_last($articles);
        $metadata = $articles[$lastId]['metadata'];
        $articles[$lastId]['metadata'] = new YencArticleMetadata($metadata->fileSize + 1,
            $metadata->part, $metadata->total, $metadata->offset + 1, $metadata->length);
        $ranges = new ArchiveRanges($volumes, static fn (string $id): array => $articles[$id], new InspectionBudget(32, 200000, 10));
        $result = (new Inspector)->inspect($ranges->read(...));
        $this->assertSame(PasswordVerdict::Incomplete, $result['verdict']);
        $this->assertSame('discontinuous-article-geometry', $result['reason']);
    }

    public function test_expired_inspection_and_retry_budgets_are_bounded(): void
    {
        $data = $this->fixture('plain');
        $result = (new Inspector)->inspect(static fn (int $offset, int $length): string => substr($data, $offset, $length), microtime(true) - 1);
        $this->assertSame(PasswordVerdict::Incomplete, $result['verdict']);
        $budget = new InspectionBudget(2, 100, 10);
        $this->assertTrue($budget->reserve(80));
        $budget->settle(80, 20);
        $this->assertTrue($budget->reserve(80));
        $budget->settle(80, 20);
        $this->assertFalse($budget->reserve(1), 'Failed provider attempts still consume the article budget.');
        $this->assertSame(60, $budget->remainingBytes());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_seven_zip_reaches_naming_through_the_processor_once(bool $sniffed): void
    {
        $data = $this->fixture('manifest');
        $config = $this->makeConfig(['processPasswords' => true]);
        $planner = new AdditionalWorkPlanner($config);
        $context = new ReleaseProcessingContext(new Release(['id' => 42]));
        $context->workPlan = $planner->plan([['title' => '"fixture.7z" yEnc', 'segments' => ['<fixture>'],
            'segmentNumbers' => [1], 'size' => strlen($data) + 900, 'segmentBytes' => [strlen($data) + 900]]], 'alt.binaries.test');
        $this->assertTrue($context->workPlan->archiveCandidates[0]->likelyFirstVolume);
        if ($sniffed) {
            $context->nzbContents = [['title' => '"opaque.bin" yEnc', 'segments' => ['<fixture>', '<tail>']]];
            $context->workPlan = new AdditionalWorkPlan(unknownPayloadCandidates: [
                new UnknownPayloadCandidate('"opaque.bin" yEnc', '<fixture>', 2, 900, 100, 0),
            ]);
        }
        $downloads = \Mockery::mock(UsenetDownloadService::class);
        $first = ['success' => true, 'groupUnavailable' => false, 'data' => $sniffed ? substr($data, 0, 32) : $data,
            'metadata' => new YencArticleMetadata(strlen($data), 1, $sniffed ? 2 : 1, 0, $sniffed ? 32 : strlen($data))];
        $downloads->shouldReceive('downloadInspectionArticle')->once()->with('<fixture>', '', \Mockery::type(InspectionBudget::class))->andReturn($first);
        if ($sniffed) {
            $downloads->shouldReceive('download')->once()->andReturn($first);
            $downloads->shouldReceive('downloadInspectionArticle')->once()->with('<tail>', '', \Mockery::type(InspectionBudget::class))
                ->andReturn(['success' => true, 'groupUnavailable' => false, 'data' => substr($data, 32),
                    'metadata' => new YencArticleMetadata(strlen($data), 2, 2, 32, strlen($data) - 32)]);
        }
        $manager = \Mockery::mock(ReleaseFileManager::class);
        $manager->shouldReceive('processReleaseNameFromRar')->once()->withArgs(function (array $summary, ReleaseProcessingContext $actual) use ($context): bool {
            return $actual === $context && in_array('nested/Descriptive.Title.2026.mkv', array_column($summary['file_list'], 'name'), true);
        });
        $manager->shouldReceive('addFileInfo')->twice()->andReturn(true);
        $processor = new ReleaseProcessor($config,
            \Mockery::mock(NzbContentParser::class), $planner,
            new ArchiveExtractionService($config), \Mockery::mock(MediaExtractionService::class),
            $downloads, $manager, \Mockery::mock(ReleaseFilesArchiveFallback::class),
            \Mockery::mock(TempWorkspaceService::class), \Mockery::mock(ConsoleOutputService::class));
        if ($sniffed) {
            (new \ReflectionMethod($processor, 'processUnknownPayloadCandidates'))->invoke($processor, $context);
        } else {
            $method = new \ReflectionMethod($processor, 'processNzbCompressedFiles');
            $tried = [];
            $method->invokeArgs($processor, [$context, false, &$tried]);
            $method->invokeArgs($processor, [$context, false, &$tried]);
        }
        $this->assertSame(PasswordVerdict::VerifiedUnencrypted, $context->passwordVerdict);
    }

    /**
     * Fixtures generated with 7-Zip 26.03 from SHA-256(LE32(0..8191)),
     * concatenated, using -t7z -m0=Copy -v64k. encrypted: -mhe=on,
     * visible: -mhe=off; both use -pfixture-only-pass. plain has no password.
     * manifest uses -mhc=on with a synthetic nested filename and content.
     */
    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/SevenZip/'.$name.'.7z'));
    }

    private function replaceStart(string $data, string $fields): string
    {
        return substr($data, 0, 8).pack('V', crc32($fields)).$fields.substr($data, 32);
    }

    /** @return array{list<ArchiveCandidate>, array<string, array{success: bool, data: string, metadata: YencArticleMetadata}>} */
    private function posting(string $data, int $volumeSize, int $partSize = 16384): array
    {
        $volumes = $articles = [];
        foreach (str_split($data, $volumeSize) as $v => $volume) {
            $parts = str_split($volume, $partSize);
            $ids = [];
            foreach ($parts as $p => $part) {
                $id = 'v'.$v.'p'.$p;
                $ids[] = $id;
                $articles[$id] = ['success' => true, 'data' => $part,
                    'metadata' => new YencArticleMetadata(strlen($volume), $p + 1, count($parts), $p * $partSize, strlen($part))];
            }
            $volumes[] = new ArchiveCandidate('"fixture.7z.'.sprintf('%03d', $v + 1).'" yEnc', [$ids[0]], $v === 0, $v, array_slice($ids, 1));
        }

        return [$volumes, $articles];
    }
}
