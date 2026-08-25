<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use PHPUnit\Framework\TestCase;

class FilenamePredbPrecedenceTest extends TestCase
{
    public function test_filename_check_consults_predb_before_generic_extraction(): void
    {
        $updates = new RecordingFilenamePrecedenceUpdater;
        $service = new TestableFilenamePrecedenceService($updates);

        $service->checkName(
            (object) [
                'releases_id' => 229,
                'searchname' => 'Current.Release.2012.DVDR-SCREAM',
                'textstring' => 'tolotg-scream.rar',
            ],
            false,
            'Filenames, ',
            true,
            false,
        );

        $this->assertSame(['predb'], $updates->attempts);
    }
}

class TestableFilenamePrecedenceService extends NameFixingService
{
    public function __construct(ReleaseUpdateService $updates)
    {
        $this->updateService = $updates;
        $this->fileExtractor = new FileNameExtractor;
    }

    protected function preDbFileCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        assert($this->updateService instanceof RecordingFilenamePrecedenceUpdater);
        $this->updateService->attempts[] = 'predb';
        $this->updateService->matched = true;

        return true;
    }
}

class RecordingFilenamePrecedenceUpdater extends ReleaseUpdateService
{
    /** @var list<string> */
    public array $attempts = [];

    public function __construct()
    {
        $this->matched = false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function checkPreDbMatch(object $release, string $textstring): ?array
    {
        return null;
    }

    public function updateRelease(
        object|array $release,
        string $name,
        string $method,
        bool $echo,
        string $type,
        bool $nameStatus,
        bool $show,
        ?int $preId = 0,
        bool $descriptiveTitleCandidate = false,
    ): void {
        $this->attempts[] = 'generic';
        $this->matched = true;
    }

    public function updateSingleColumn(string $column, int $status, int $id): void {}
}
