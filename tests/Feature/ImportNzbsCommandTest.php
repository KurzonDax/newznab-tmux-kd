<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\TestCase;

final class ImportNzbsCommandTest extends TestCase
{
    private FakeNzbImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nntmux.echocli' => true]);
        $this->importer = new FakeNzbImportService;
        $this->app->instance(NzbImportService::class, $this->importer);
    }

    /**
     * @return array<string, array{0: \Closure(self): array<string, mixed>}>
     */
    public static function usageErrorProvider(): array
    {
        return [
            'both --folder and --file' => [fn (self $test): array => ['--folder' => $test->makeTempDirectory('folder'), '--file' => $test->nzbFile()]],
            'missing file' => [fn (self $test): array => ['--file' => $test->makeTempPath('missing', '.nzb')]],
            'empty file option' => [fn (self $test): array => ['--file' => '']],
            'directory instead of a file' => [function (self $test): array {
                $directory = $test->makeTempPath('dir', '.nzb');
                mkdir($directory);

                return ['--file' => $directory];
            }],
            'unreadable file' => [function (self $test): array {
                $file = $test->nzbFile();
                chmod($file, 0000);

                return ['--file' => $file];
            }],
            'wrong extension' => [fn (self $test): array => ['--file' => $test->nzbFile('.txt')]],
            'file in a staging folder' => [function (self $test): array {
                $folder = $test->makeTempDirectory('staged');
                file_put_contents($folder.'/release.nzb', '<nzb/>');
                file_put_contents($folder.'/'.NzbUploadManifestService::FILENAME, '{}');

                return ['--file' => $folder.'/release.nzb'];
            }],
            '--name without --file' => [fn (self $test): array => ['--folder' => $test->makeTempDirectory('folder'), '--name' => 'Title']],
            'blank --name' => [fn (self $test): array => ['--file' => $test->nzbFile(), '--name' => "  \t "]],
            '256-character --name' => [fn (self $test): array => ['--file' => $test->nzbFile(), '--name' => str_repeat('é', 256)]],
            '--name with --filename' => [fn (self $test): array => ['--file' => $test->nzbFile(), '--name' => 'Title', '--filename' => true]],
            '--json without --file' => [fn (self $test): array => ['--folder' => $test->makeTempDirectory('folder'), '--json' => true]],
        ];
    }

    /**
     * @param  \Closure(self): array<string, mixed>  $arguments
     */
    #[DataProvider('usageErrorProvider')]
    public function test_usage_errors_exit_one_with_a_stderr_message_and_empty_stdout(\Closure $arguments): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCommand($arguments($this));

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertSame(1, substr_count(trim($stderr), "\n") + 1, 'One stderr line: '.$stderr);
        $this->assertNotSame('', trim($stderr));
        $this->assertSame([], $this->importer->calls);
    }

    public function test_a_staging_folder_is_named_in_the_message(): void
    {
        $folder = $this->makeTempDirectory('staged');
        file_put_contents($folder.'/release.nzb', '<nzb/>');
        file_put_contents($folder.'/'.NzbUploadManifestService::FILENAME, '{}');

        [, , $stderr] = $this->runCommand(['--file' => $folder.'/release.nzb']);

        $this->assertStringContainsString('staged uploads are imported with --folder', $stderr);
    }

    public function test_a_255_character_name_and_uppercase_gz_extension_are_accepted(): void
    {
        $file = $this->nzbFile('.NZB.GZ');
        $name = str_repeat('é', 255);
        $this->importer->results = [$this->importResult($file, NzbImportStatus::Inserted, releaseId: 9, guid: 'abc')];

        [$exitCode] = $this->runCommand(['--file' => $file, '--name' => $name]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($name, $this->importer->calls[0]['releaseName']);
    }

    /**
     * @return array<string, array{0: NzbImportStatus, 1: int|null, 2: string|null, 3: bool, 4: string|null, 5: string|null}>
     */
    public static function statusProvider(): array
    {
        return [
            'inserted' => [NzbImportStatus::Inserted, 12, 'guid-inserted', false, null, null],
            'absorbed duplicate' => [NzbImportStatus::Duplicate, 41, 'guid-existing', true, 'absorbed', null],
            'deferred duplicate' => [NzbImportStatus::Duplicate, 41, 'guid-existing', false, 'deferred', null],
            'collectionhash duplicate' => [NzbImportStatus::Duplicate, 41, 'guid-existing', false, null, null],
            'blacklisted' => [NzbImportStatus::Blacklisted, null, null, false, null, 'Subject is blacklisted: Some/Subject'],
            'no group' => [NzbImportStatus::NoGroup, null, null, false, null, 'No group found for x (one of alt.x are missing'],
            'failed' => [NzbImportStatus::Failed, null, null, false, null, "ERROR: Problem inserting: bad \xff subject"],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_each_status_prints_exactly_one_json_line_and_exits_zero(
        NzbImportStatus $status,
        ?int $releaseId,
        ?string $guid,
        bool $absorbed,
        ?string $absorbOutcome,
        ?string $error,
    ): void {
        $file = $this->nzbFile();
        $this->importer->results = [$this->importResult($file, $status, $releaseId, $guid, $absorbed, $absorbOutcome, $error)];

        [$exitCode, $stdout, $stderr] = $this->runCommand(['--file' => $file, '--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringEndsWith("\n", $stdout);
        $this->assertSame(1, substr_count($stdout, "\n"), 'stdout: '.$stdout);
        $this->assertSame([
            'status' => $status->value,
            'release_id' => $releaseId,
            'guid' => $guid,
            'absorbed' => $absorbed,
            'absorb_outcome' => $absorbOutcome,
            'error' => $error === null ? null : str_replace("\xff", "\u{FFFD}", $error),
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringContainsString(FakeNzbImportService::PROGRESS, $stderr);
        $this->assertStringNotContainsString(FakeNzbImportService::PROGRESS, $stdout);
    }

    public function test_slashes_and_unicode_are_not_escaped_in_the_json_line(): void
    {
        $file = $this->nzbFile();
        $this->importer->results = [$this->importResult($file, NzbImportStatus::Blacklisted, error: 'Subject is blacklisted: Fünf/Sechs')];

        [, $stdout] = $this->runCommand(['--file' => $file, '--json' => true]);

        $this->assertStringContainsString('"error":"Subject is blacklisted: Fünf/Sechs"', $stdout);
    }

    public function test_no_groups_gives_exit_one_and_empty_stdout(): void
    {
        $this->importer->return = false;

        [$exitCode, $stdout, $stderr] = $this->runCommand(['--file' => $this->nzbFile(), '--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString(FakeNzbImportService::PROGRESS, $stderr);
    }

    public function test_an_uncaught_exception_gives_exit_one_and_empty_stdout(): void
    {
        $this->importer->throw = new \RuntimeException('importer exploded');

        [$exitCode, $stdout, $stderr] = $this->runCommand(['--file' => $this->nzbFile(), '--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('importer exploded', $stderr);
    }

    public function test_file_mode_without_json_exits_zero_with_a_result_and_one_without(): void
    {
        $file = $this->nzbFile();
        $this->importer->results = [$this->importResult($file, NzbImportStatus::Failed, error: 'ERROR: Unable to read: '.$file)];

        [$withResult, $stdout] = $this->runCommand(['--file' => $file]);
        $this->assertSame(0, $withResult);
        $this->assertStringNotContainsString('{"status"', $stdout);

        $this->importer->results = [];
        [$withoutResult] = $this->runCommand(['--file' => $file]);
        $this->assertSame(1, $withoutResult);
    }

    public function test_file_mode_imports_only_that_file_and_keeps_the_release(): void
    {
        $file = $this->nzbFile();
        $this->importer->results = [$this->importResult($file, NzbImportStatus::Inserted, releaseId: 3, guid: 'g')];

        $this->runCommand([
            '--file' => $file,
            '--name' => 'Exact Title',
            '--skip-blacklist' => true,
            '--delete' => true,
            '--source' => '2',
        ]);

        $this->assertCount(1, $this->importer->calls);
        $call = $this->importer->calls[0];
        $this->assertSame([$file], $call['files']);
        $this->assertSame('Exact Title', $call['releaseName']);
        $this->assertTrue($call['skipBlacklist']);
        $this->assertTrue($call['keepRelease']);
        $this->assertTrue($call['delete']);
        $this->assertSame(2, $call['source']);
    }

    public function test_folder_mode_passes_keep_release_false(): void
    {
        $folder = $this->makeTempDirectory('folder');
        file_put_contents($folder.'/one.nzb', '<nzb/>');

        [$exitCode] = $this->runCommand(['--folder' => $folder, '--skip-blacklist' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($this->importer->calls[0]['keepRelease']);
        $this->assertTrue($this->importer->calls[0]['skipBlacklist']);
        $this->assertNull($this->importer->calls[0]['releaseName']);
    }

    /**
     * @return array<string, array{0: \Closure(self): array<string, mixed>, 1: string}>
     */
    public static function folderMessageProvider(): array
    {
        return [
            'no folder' => [fn (self $test): array => [], 'Folder path must not be empty'],
            'empty --folder=' => [fn (self $test): array => ['--folder' => ''], 'Folder path must not be empty'],
            'missing folder' => [fn (self $test): array => ['--folder' => '/nonexistent/nzb-folder'], 'Folder path does not exist: /nonexistent/nzb-folder'],
        ];
    }

    /**
     * @param  \Closure(self): array<string, mixed>  $arguments
     */
    #[DataProvider('folderMessageProvider')]
    public function test_folder_mode_keeps_exit_zero_and_its_stdout_message(\Closure $arguments, string $message): void
    {
        [$exitCode, $stdout] = $this->runCommand($arguments($this));

        $this->assertSame(0, $exitCode);
        $this->assertSame($message, trim($stdout));
        $this->assertSame([], $this->importer->calls);
    }

    public function test_folder_mode_exits_zero_when_the_service_throws(): void
    {
        $folder = $this->makeTempDirectory('folder');
        file_put_contents($folder.'/one.nzb', '<nzb/>');
        $this->importer->throw = new \RuntimeException('folder import exploded');

        [$exitCode, $stdout] = $this->runCommand(['--folder' => $folder]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('folder import exploded', $stdout);
    }

    public function test_folder_mode_records_manifests_with_only_the_four_outcome_arguments(): void
    {
        $folder = $this->makeTempDirectory('uploads');
        $manifests = app(NzbUploadManifestService::class);
        $outcomes = [
            'failed' => $this->importResult($folder.'/failed/release.nzb', NzbImportStatus::Failed, error: 'ERROR: specific importer text'),
            'duplicate' => $this->importResult($folder.'/duplicate/release.nzb', NzbImportStatus::Duplicate, 41, 'guid-existing', false, 'deferred'),
        ];
        foreach (array_keys($outcomes) as $upload) {
            mkdir($folder.'/'.$upload);
            file_put_contents($folder.'/'.$upload.'/release.nzb', '<nzb/>');
            $manifests->create($folder.'/'.$upload, $upload, 'release.nzb', null);
        }
        $this->importer->results = array_values($outcomes);

        [$exitCode] = $this->runCommand(['--folder' => $folder]);

        $this->assertSame(0, $exitCode);
        $failed = $manifests->read($folder.'/failed/'.NzbUploadManifestService::FILENAME);
        $this->assertSame('NZB import ended with status failed', $failed['last_error']);
        $duplicate = $manifests->read($folder.'/duplicate/'.NzbUploadManifestService::FILENAME);
        $this->assertSame(NzbUploadManifestService::STATE_NZB_DUPLICATE, $duplicate['state']);
        $this->assertNull($duplicate['release_id']);
        $this->assertNull($duplicate['release_guid']);
        $this->assertSame('NZB import detected an existing duplicate release', $duplicate['last_error']);
    }

    public function nzbFile(string $extension = '.nzb'): string
    {
        $path = $this->makeTempPath('import-command', $extension);
        file_put_contents($path, '<nzb/>');

        return $path;
    }

    /**
     * @return array{path: string, status: NzbImportStatus, release_id: int|null, release_guid: string|null, absorbed: bool, absorb_outcome: string|null, error: string|null}
     */
    private function importResult(
        string $path,
        NzbImportStatus $status,
        ?int $releaseId = null,
        ?string $guid = null,
        bool $absorbed = false,
        ?string $absorbOutcome = null,
        ?string $error = null,
    ): array {
        return [
            'path' => $path,
            'status' => $status,
            'release_id' => $releaseId,
            'release_guid' => $guid,
            'absorbed' => $absorbed,
            'absorb_outcome' => $absorbOutcome,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCommand(array $arguments): array
    {
        $output = new SplitConsoleOutput;
        $exitCode = Artisan::call('nntmux:import-nzbs', $arguments, $output);

        return [$exitCode, $output->stdout(), $output->stderr()];
    }
}

/**
 * Records each import request, prints a known progress line and reports the queued results.
 */
final class FakeNzbImportService extends NzbImportService
{
    public const string PROGRESS = 'fake import progress line';

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public ?\Throwable $throw = null;

    public bool|string $return = true;

    public function __construct()
    {
        $this->echoCLI = true;
        $this->browser = false;
    }

    public function beginImport(
        mixed $filesToProcess,
        bool $useNzbName = false,
        bool $delete = false,
        bool $deleteFailed = false,
        int $source = 1,
        ?callable $resultCallback = null,
        ?string $releaseName = null,
        bool $skipBlacklist = false,
        bool $keepRelease = false,
    ): bool|string {
        $this->calls[] = [
            'files' => array_map(
                static fn (mixed $file): string => $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file,
                array_values($filesToProcess),
            ),
            'useNzbName' => $useNzbName,
            'delete' => $delete,
            'deleteFailed' => $deleteFailed,
            'source' => $source,
            'releaseName' => $releaseName,
            'skipBlacklist' => $skipBlacklist,
            'keepRelease' => $keepRelease,
        ];
        $this->echoOut(self::PROGRESS);

        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->return !== false && $resultCallback !== null) {
            foreach ($this->results as $result) {
                $resultCallback($result);
            }
        }

        return $this->return;
    }
}

/**
 * A console output whose stdout and stderr are two separate in-memory streams.
 */
final class SplitConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'w+'));
        $this->stderr = new StreamOutput(fopen('php://memory', 'w+'));
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('Sections are not used by this command.');
    }

    public function stdout(): string
    {
        return self::contents($this);
    }

    public function stderr(): string
    {
        return $this->stderr instanceof StreamOutput ? self::contents($this->stderr) : '';
    }

    private static function contents(StreamOutput $output): string
    {
        $stream = $output->getStream();
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
