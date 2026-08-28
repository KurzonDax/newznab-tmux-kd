<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Support\Data\NzbReplaceResult;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Each internal failure mode of {@see NzbService::replaceNzbContents()} must
 * surface a distinct, specific reason instead of a bare false. Forced-failure
 * subclasses follow the NzbCreationReliabilityTest pattern.
 */
final class NzbReplaceContentsResultTest extends TestCase
{
    private string $nzbDirectory;

    private string $guid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        $this->nzbDirectory = $this->makeTempDirectory('replace-nzb').DIRECTORY_SEPARATOR;
        config(['nntmux_settings.path_to_nzbs' => $this->nzbDirectory]);

        $this->guid = str_repeat('a', 36);
    }

    public function test_a_successful_replace_swaps_the_stored_contents_atomically(): void
    {
        $nzb = $this->service();
        $this->writeStoredNzb($nzb, '<nzb>old</nzb>');

        $result = $nzb->replaceNzbContents($this->guid, '<nzb>new</nzb>');

        $this->assertTrue($result->success);
        $this->assertSame(NzbReplaceResult::FAILURE_NONE, $result->failureType);
        $this->assertSame('<nzb>new</nzb>', $nzb->readNzbContents($this->guid));
    }

    public function test_a_missing_stored_nzb_reports_the_missing_nzb_mode(): void
    {
        $result = $this->service()->replaceNzbContents($this->guid, '<nzb>new</nzb>');

        $this->assertFalse($result->success);
        $this->assertSame(NzbReplaceResult::FAILURE_MISSING_NZB, $result->failureType);
        $this->assertTrue($result->isDeterministicFailure());
        $this->assertStringContainsString($this->guid, $result->reason);
    }

    public function test_a_temporary_file_open_failure_reports_the_temp_open_mode(): void
    {
        $nzb = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            protected function openGzipFile(string $path): mixed
            {
                return false;
            }
        };
        $this->writeStoredNzb($nzb, '<nzb>old</nzb>');

        $result = $nzb->replaceNzbContents($this->guid, '<nzb>new</nzb>');

        $this->assertFalse($result->success);
        $this->assertSame(NzbReplaceResult::FAILURE_TEMP_OPEN, $result->failureType);
        $this->assertTrue($result->isTransientFailure());
        $this->assertStringContainsString('.nzb.gz.tmp.', $result->reason);
        $this->assertSame('<nzb>old</nzb>', $nzb->readNzbContents($this->guid));
    }

    public function test_a_short_gzip_write_reports_the_write_mode(): void
    {
        $nzb = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            protected function writeGzipContents(mixed $gz, string $contents): int|false
            {
                return 3;
            }
        };
        $this->writeStoredNzb($nzb, '<nzb>old</nzb>');

        $result = $nzb->replaceNzbContents($this->guid, '<nzb>new</nzb>');

        $this->assertFalse($result->success);
        $this->assertSame(NzbReplaceResult::FAILURE_WRITE, $result->failureType);
        $this->assertTrue($result->isTransientFailure());
        $this->assertStringContainsString('3 of '.strlen('<nzb>new</nzb>').' bytes', $result->reason);
        $this->assertSame('<nzb>old</nzb>', $nzb->readNzbContents($this->guid));
        $this->assertSame([], $this->leftoverTemporaryFiles());
    }

    public function test_a_rename_failure_reports_the_rename_mode_and_keeps_the_old_nzb(): void
    {
        $nzb = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            protected function moveTemporaryNzbIntoPlace(string $temporaryPath, string $finalPath): bool
            {
                return false;
            }
        };
        $this->writeStoredNzb($nzb, '<nzb>old</nzb>');

        $result = $nzb->replaceNzbContents($this->guid, '<nzb>new</nzb>');

        $this->assertFalse($result->success);
        $this->assertSame(NzbReplaceResult::FAILURE_RENAME, $result->failureType);
        $this->assertTrue($result->isTransientFailure());
        $this->assertStringContainsString($this->guid.'.nzb.gz', $result->reason);
        $this->assertSame('<nzb>old</nzb>', $nzb->readNzbContents($this->guid));
        $this->assertSame([], $this->leftoverTemporaryFiles());
    }

    public function test_the_four_failure_modes_are_distinct(): void
    {
        $this->assertCount(4, array_unique([
            NzbReplaceResult::FAILURE_MISSING_NZB,
            NzbReplaceResult::FAILURE_TEMP_OPEN,
            NzbReplaceResult::FAILURE_WRITE,
            NzbReplaceResult::FAILURE_RENAME,
        ]));
    }

    private function service(): NzbService
    {
        return new NzbService(app(CollectionCleanupService::class));
    }

    private function writeStoredNzb(NzbService $nzb, string $contents): void
    {
        file_put_contents($nzb->getNzbPath($this->guid, 0, true), gzencode($contents));
    }

    /**
     * @return list<string>
     */
    private function leftoverTemporaryFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->nzbDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        $leftovers = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains($file->getFilename(), '.tmp.')) {
                $leftovers[] = $file->getPathname();
            }
        }

        return $leftovers;
    }
}
