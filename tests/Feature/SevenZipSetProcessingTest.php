<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\PasswordVerdict;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\FreeDiskGuard;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\CollectionCleanupService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ExecutableReleaseDiscardService;
use App\Services\Releases\PreviewGenerationPolicy;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\BuildsSevenZipPostings;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

/**
 * Whole release runs over generated 7z postings: obfuscated sets found by the
 * split-set search, opened with the NZB password, and previewed from inside.
 */
class SevenZipSetProcessingTest extends TestCase
{
    use BuildsSevenZipPostings;
    use CreatesProcessingConfiguration;
    use IsolatedSqliteDatabase;

    private const string PASSWORD = 'fixture-only-pass';

    /** @var list<array{bytes: string, foundSample: bool, foundVideo: bool}> */
    private array $videos = [];

    private ?ReleaseProcessingContext $currentContext = null;

    private SevenZipRecordingWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'name']);
        $tables->create('root_categories', ['id', 'title', 'discard_executables', 'generate_previews']);
        $tables->create('categories', ['id', 'title', 'root_categories_id']);
        $tables->create('releases', ['id', 'guid', 'name', 'searchname', 'size', 'groups_id', 'nfostatus', 'categories_id',
            'iscategorized', 'passwordstatus', 'haspreview', 'jpgstatus', 'videostatus', 'nzbstatus', 'rarinnerfilecount',
            'pp_timeout_count', 'postdate', 'proc_pp', 'isrenamed', 'additional_pp_claimed_at', 'additional_pp_claim_token']);
        $tables->create('release_files', ['releases_id', 'name', 'size', 'crc32', 'created_at', 'updated_at', 'passworded']);
        $tables->create('par_hashes', ['releases_id', 'hash']);
        $tables->create('predb', ['id', 'title']);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies', 'discard_executables' => 1, 'generate_previews' => 1]);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'Movies > HD', 'root_categories_id' => 2000]);
        DB::table('releases')->insert([
            'id' => 1, 'guid' => 'guid-7z', 'name' => 'Example', 'searchname' => 'Example', 'size' => 30000, 'groups_id' => 1,
            'nfostatus' => 1, 'categories_id' => 2040, 'iscategorized' => 1, 'passwordstatus' => -1, 'haspreview' => -1,
            'jpgstatus' => 0, 'videostatus' => 0, 'nzbstatus' => 1, 'rarinnerfilecount' => 0, 'pp_timeout_count' => 0,
            'postdate' => '2026-11-09 12:00:00', 'proc_pp' => 0, 'isrenamed' => 0,
            'additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'token',
        ]);
        Search::swap(Mockery::mock()->shouldIgnoreMissing());
        $this->workspace = new SevenZipRecordingWorkspace;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_nzb_password_opens_an_obfuscated_header_encrypted_set(): void
    {
        $this->requireSevenZip();

        [$result, $context] = $this->processRelease($this->obfuscatedEncrypted(), self::PASSWORD);

        $this->assertSame(['a.txt', 'b.pad', 'c.mp4'], DB::table('release_files')->orderBy('name')->pluck('name')->all());
        $this->assertSame([0, 0, 0], DB::table('release_files')->pluck('passworded')->map(intval(...))->all());
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, $this->release()->passwordstatus);
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
        $this->assertCount(1, $this->videos);
        $this->assertSame('ftyp', substr($this->videos[0]['bytes'], 4, 4));
        $this->assertNotSame(ProcessingOutcome::Passworded, $result->outcome);
        $this->assertWorkingSetsWereRemoved();
    }

    public function test_a_wrong_nzb_password_is_noticed_and_marks_the_release_passworded(): void
    {
        $this->requireSevenZip();

        [, $context] = $this->processRelease($this->obfuscatedEncrypted(), 'not-the-password');

        $this->assertTrue($context->nzbPasswordRejected);
        $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $this->release()->passwordstatus);
        $this->assertSame([], $this->requestedIds('compressed'));
        $this->assertSame([], $this->videos);
    }

    public function test_a_header_encrypted_set_with_no_nzb_password_is_passworded(): void
    {
        [, $context] = $this->processRelease($this->obfuscatedEncrypted(), null);

        $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $this->release()->passwordstatus);
        $this->assertSame(PasswordVerdict::VerifiedEncrypted, $context->passwordVerdict);
        $this->assertSame([], $this->commands);
    }

    public function test_a_search_that_reaches_the_release_timeout_is_retried_not_counted_toward_deletion(): void
    {
        $contents = $this->obfuscatedEncrypted();
        $this->downloadHook = function (array $ids): void {
            if ($ids === ['p2s0@fixture'] && $this->currentContext !== null) {
                $this->currentContext->startTime = hrtime(true) - 10 * 1_000_000_000;
            }
        };

        [$result, $context] = $this->processRelease($contents, self::PASSWORD, ['releaseProcessingTimeout' => 5], expectTimeoutHandling: false);

        $this->assertContains('7z-inspection:seven-zip-volume-search-timeout', $context->unsupportedReasons());
        $this->assertSame('Processing timed out after bounded archive inspection.', $result->reason);
        $release = $this->release();
        $this->assertSame(-1, $release->passwordstatus);
        $this->assertSame(1, $release->pp_timeout_count);
        $this->assertNotContains('p0s0@fixture', $this->requestedIds('payload-sniff'), 'The search stopped at the timeout.');
    }

    public function test_preview_generation_disabled_still_gets_media_info_from_the_set(): void
    {
        $this->requireSevenZip();

        $this->processRelease($this->obfuscatedEncrypted(), self::PASSWORD, previewsEnabled: false);

        $this->assertCount(1, $this->videos);
        $this->assertTrue($this->videos[0]['foundSample'], 'No preview image is attempted.');
        $this->assertTrue($this->videos[0]['foundVideo'], 'No sample video is attempted.');
        $this->assertWorkingSetsWereRemoved();
    }

    public function test_a_listing_that_discards_the_release_makes_no_preview(): void
    {
        $this->requireSevenZip();
        $discard = new SevenZipRecordingDiscardService;
        $rewrite = static function (string $command, array $result): array {
            if (str_contains($command, '" l -slt ') && $result['exitCode'] === 0) {
                $result['output'] .= "Path = Setup.exe\nSize = 10\nPacked Size = 16\nAttributes = A\nBlock = 3\n\n";
            }

            return $result;
        };

        [, $context] = $this->processRelease($this->obfuscatedEncrypted(), self::PASSWORD, runner: $this->recordingRunner($rewrite), discard: $discard);

        $this->assertSame(['Setup.exe'], $discard->discarded);
        $this->assertTrue($context->releaseDiscarded);
        $this->assertSame([], $this->requestedIds('compressed'));
        $this->assertSame([], $this->videos);
        $this->assertWorkingSetsWereRemoved();
    }

    public function test_a_named_set_gets_its_preview_through_the_named_path(): void
    {
        $this->requireSevenZip();
        $contents = $this->sevenZipPosting($this->sevenZipFixture('obfuscated-plain'), 6144, 4096, named: true);

        [, $context] = $this->processRelease($contents, null);

        $this->assertCount(1, $this->videos);
        $this->assertSame('ftyp', substr($this->videos[0]['bytes'], 4, 4));
        $this->assertSame(6144 - 72, strlen($this->videos[0]['bytes']));
        $this->assertSame([], $this->requestedIds('payload-sniff'));
        $this->assertSame(PasswordVerdict::VerifiedUnencrypted, $context->passwordVerdict);
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, $this->release()->passwordstatus);
        $this->assertWorkingSetsWereRemoved();
    }

    /**
     * Fixture (a) in parts of 7168 bytes, every part two articles, with the
     * first part last in NZB order.
     *
     * @return list<array<string, mixed>>
     */
    private function obfuscatedEncrypted(): array
    {
        return $this->sevenZipPosting($this->sevenZipFixture('obfuscated-encrypted'), 7168, 4096, [1, 2, 3, 0]);
    }

    /**
     * @param  list<array<string, mixed>>  $contents
     * @param  array<string, mixed>  $config
     * @param  (\Closure(string): array{exitCode: int, output: string})|null  $runner
     * @return array{ReleaseProcessingResult, ReleaseProcessingContext}
     */
    private function processRelease(
        array $contents,
        ?string $password,
        array $config = [],
        bool $previewsEnabled = true,
        ?\Closure $runner = null,
        ?ExecutableReleaseDiscardService $discard = null,
        bool $expectTimeoutHandling = true,
    ): array {
        $config = $this->makeConfig($config + [
            'processPasswords' => true,
            'processMediaInfo' => true,
            'processThumbnails' => true,
            'processVideo' => true,
            'sevenZipPath' => '/usr/bin/7z',
            'payloadSniffMaxCandidates' => 1,
            'payloadSniffSmallSegmentLimit' => 1,
        ]);
        $manager = Mockery::mock(ReleaseFileManager::class, [
            $config, new ReleaseImageService, new NfoService, new NzbService(new CollectionCleanupService),
            new SevenZipNameFixingService, null, null, null, $discard,
        ])->makePartial();
        if (! $expectTimeoutHandling) {
            $manager->shouldNotReceive('handleReleaseTimeout');
        }
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->with('guid-7z')
            ->andReturn(['error' => null, 'password' => $password, 'contents' => $contents]);
        $downloads = $this->sevenZipDownloader();
        $context = $this->currentContext = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $media = Mockery::mock(MediaExtractionService::class);
        $media->shouldReceive('processVideoFile')->andReturnUsing(function (string $path, ReleaseProcessingContext $context): array {
            $this->videos[] = ['bytes' => (string) file_get_contents($path), 'foundSample' => $context->foundSample, 'foundVideo' => $context->foundVideo];
            $context->foundMediaInfo = true;

            return ['sample' => false, 'video' => false, 'mediaInfo' => true];
        });
        $output = Mockery::mock(ConsoleOutputService::class);
        $output->shouldIgnoreMissing();
        $fallback = Mockery::mock(ReleaseFilesArchiveFallback::class);
        $fallback->shouldIgnoreMissing();

        $processor = new ReleaseProcessor(
            $config,
            $parser,
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config, processRunner: $runner ?? $this->recordingRunner()),
            $media,
            $downloads,
            $manager,
            $fallback,
            $this->workspace,
            $output,
            previewPolicy: new SevenZipPreviewPolicy($previewsEnabled),
            freeDiskGuard: new FreeDiskGuard(static fn (string $path): float => 900.0, static fn (string $path): float => 1000.0),
        );

        $result = $processor->process($context, $this->makeTempDirectory('nntmux-7z-release').'/');

        return [$result, $context];
    }

    private function release(): Release
    {
        $release = Release::query()->find(1);
        $this->assertNotNull($release);
        $release->passwordstatus = (int) $release->passwordstatus;
        $release->pp_timeout_count = (int) $release->pp_timeout_count;

        return $release;
    }

    private function assertWorkingSetsWereRemoved(): void
    {
        $this->assertNotSame([], $this->workspace->cleared);
        $this->assertSame([false], array_values(array_unique($this->workspace->cleared)),
            '<tmpPath>sevenzip/ is gone before the release workspace is cleared.');
        $this->assertSame([], $this->workspace->listedSevenZip, 'The nested-archive sweep never saw a working set.');
    }
}

final class SevenZipRecordingWorkspace extends TempWorkspaceService
{
    /** @var list<bool> Whether a working set was still on disk at each clear. */
    public array $cleared = [];

    /** @var list<string> */
    public array $listedSevenZip = [];

    public function clearDirectory(string $path, bool $preserveRoot = true): void
    {
        $this->cleared[] = is_dir(rtrim($path, '/').'/sevenzip');
        parent::clearDirectory($path, $preserveRoot);
    }

    public function listFiles(string $path, string $pattern = ''): array
    {
        $files = parent::listFiles($path, $pattern);
        foreach ($files as $file) {
            $name = is_array($file) ? $file[0] : $file->getPathname();
            if (str_contains($name, 'sevenzip')) {
                $this->listedSevenZip[] = $name;
            }
        }

        return $files;
    }
}

final class SevenZipRecordingDiscardService extends ExecutableReleaseDiscardService
{
    /** @var list<string> */
    public array $discarded = [];

    public function discard(Release $release, string $matchedFileName): bool
    {
        $this->discarded[] = $matchedFileName;

        return true;
    }
}

final class SevenZipNameFixingService extends NameFixingService
{
    public function matchPreDbFiles(object $release, bool $echo, bool $nameStatus, bool $show): int
    {
        return 0;
    }
}

final class SevenZipPreviewPolicy extends PreviewGenerationPolicy
{
    public function __construct(private readonly bool $enabled) {}

    public function generationEnabledForCategory(int $categoriesId): bool
    {
        return $this->enabled;
    }
}
