<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Termwind\renderUsing;

class ImportNzbs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:import-nzbs
        {--folder= : Import folder path}
        {--file= : Import exactly this one NZB file (.nzb or .nzb.gz) and keep its release}
        {--name= : Exact release name for the --file import}
        {--filename : Use filename true or false}
        {--json : Print the --file result as one JSON line on stdout}
        {--skip-blacklist : Skip blacklist and whitelist checks}
        {--delete : Delete files after import}
        {--delete-failed : Delete files after failed import}
        {--source= : Source of the NZB files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import nzb files for indexing';

    /**
     * Execute the console command.
     */
    public function handle(NzbUploadManifestService $manifests): int
    {
        $usageError = $this->usageError();
        if ($usageError !== null) {
            $this->writeError($usageError);

            return self::FAILURE;
        }

        $file = $this->option('file');
        if ($file !== null) {
            return $this->importFile((string) $file);
        }

        $this->importFolder($manifests);

        return self::SUCCESS;
    }

    private function usageError(): ?string
    {
        $folder = $this->option('folder');
        $file = $this->option('file');
        $name = $this->option('name');

        if ($folder !== null && $file !== null) {
            return 'Use either --folder or --file, not both';
        }

        if ($file !== null) {
            $fileError = $this->fileError((string) $file);
            if ($fileError !== null) {
                return $fileError;
            }
        }

        if ($name !== null) {
            if ($file === null) {
                return '--name requires --file';
            }
            if (trim((string) $name) === '') {
                return '--name must not be empty';
            }
            if (mb_strlen((string) $name) > 255) {
                return '--name must be at most 255 characters';
            }
            if ($this->option('filename')) {
                return 'Use either --name or --filename, not both';
            }
        }

        if ($this->option('json') && $file === null) {
            return '--json requires --file';
        }

        return null;
    }

    private function fileError(string $file): ?string
    {
        if (! is_file($file)) {
            return 'NZB file does not exist: '.$file;
        }
        if (! is_readable($file)) {
            return 'NZB file is not readable: '.$file;
        }
        if (! Str::endsWith(strtolower($file), ['.nzb', '.nzb.gz'])) {
            return 'NZB file must end in .nzb or .nzb.gz: '.$file;
        }
        if (is_file(dirname($file).DIRECTORY_SEPARATOR.NzbUploadManifestService::FILENAME)) {
            return 'staged uploads are imported with --folder';
        }

        return null;
    }

    /**
     * Import one NZB and keep its release. Exits 0 when the importer reported a result for the
     * file, whatever its status, and 1 when it reported none.
     */
    private function importFile(string $file): int
    {
        $json = (bool) $this->option('json');
        $name = $this->option('name');
        $result = null;

        // With --json, stdout carries only the result line; the importer's progress goes to stderr.
        if ($json) {
            renderUsing($this->output->getErrorStyle());
        }

        try {
            $this->writeInfo('Importing NZB file '.$file, toStderr: $json);
            app(NzbImportService::class)->beginImport(
                [$file],
                (bool) $this->option('filename'),
                (bool) $this->option('delete'),
                (bool) $this->option('delete-failed'),
                $this->source(),
                static function (array $reported) use (&$result): void {
                    $result = $reported;
                },
                releaseName: $name === null ? null : (string) $name,
                skipBlacklist: (bool) $this->option('skip-blacklist'),
                keepRelease: true,
            );
        } catch (Throwable $exception) {
            $this->writeError($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($json) {
                renderUsing(null);
            }
        }

        if ($result === null) {
            $this->writeError('No import result was reported for '.$file);

            return self::FAILURE;
        }

        if ($json) {
            $this->output->writeln($this->encodeResult($result), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function importFolder(NzbUploadManifestService $manifests): void
    {
        $folderOption = $this->option('folder');
        if (! is_string($folderOption) || trim($folderOption) === '') {
            $this->error('Folder path must not be empty');

            return;
        }

        $importFolder = rtrim($folderOption, '/\\');
        if (! File::isDirectory($importFolder)) {
            $this->error('Folder path does not exist: '.$importFolder);

            return;
        }

        $useNzbName = (bool) $this->option('filename');
        $deleteNZB = (bool) $this->option('delete');
        $deleteFailedNZB = (bool) $this->option('delete-failed');

        try {
            $files = array_values(array_filter(
                File::allFiles($importFolder),
                static function (SplFileInfo $file) use ($manifests): bool {
                    $path = $file->getPathname();
                    if (! Str::endsWith(strtolower($path), ['.nzb', '.nzb.gz'])) {
                        return true;
                    }

                    return $manifests->shouldImportNzb($path);
                }
            ));

            $this->info('Importing NZB files from '.$importFolder);
            app(NzbImportService::class)->beginImport(
                $files,
                $useNzbName,
                $deleteNZB,
                $deleteFailedNZB,
                $this->source(),
                static function (array $result) use ($manifests): void {
                    /** @var NzbImportStatus $status */
                    $status = $result['status'];
                    $manifests->recordNzbOutcome(
                        $result['path'],
                        $status,
                        $result['release_id'],
                        $result['release_guid'],
                    );
                },
                skipBlacklist: (bool) $this->option('skip-blacklist'),
                keepRelease: false,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
        }
    }

    private function source(): int
    {
        return $this->option('source') ? (int) $this->option('source') : 1;
    }

    /**
     * @param  array{status: NzbImportStatus, release_id: int|null, release_guid: string|null, absorbed: bool, absorb_outcome: string|null, error: string|null}  $result
     */
    private function encodeResult(array $result): string
    {
        return json_encode([
            'status' => $result['status']->value,
            'release_id' => $result['release_id'],
            'guid' => $result['release_guid'],
            'absorbed' => $result['absorbed'],
            'absorb_outcome' => $result['absorb_outcome'],
            'error' => $result['error'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function writeInfo(string $message, bool $toStderr): void
    {
        if ($toStderr) {
            $this->writeError($message);

            return;
        }

        $this->info($message);
    }

    private function writeError(string $message): void
    {
        $this->output->getErrorStyle()->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
