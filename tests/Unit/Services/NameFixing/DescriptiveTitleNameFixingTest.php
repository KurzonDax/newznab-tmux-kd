<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use PHPUnit\Framework\TestCase;

class DescriptiveTitleNameFixingTest extends TestCase
{
    public function test_longest_descriptive_video_title_wins_when_setting_is_on(): void
    {
        $updater = new RecordingDescriptiveTitleUpdater;
        $service = new TestableDescriptiveTitleNameFixingService($updater, true);

        $service->processFiles($this->obfuscatedRelease(), [
            (object) ['textstring' => '2016-04-16 - Solana A - Before The Party 2.mp4'],
            (object) ['textstring' => '2016-04-17 - Anita Bellini - Playful And Petite (4k).mp4'],
        ]);

        $this->assertSame('2016-04-17 - Anita Bellini - Playful And Petite (4k).mp4', $updater->descriptiveName);
        $this->assertSame('fileCheck: Descriptive title', $updater->descriptiveMethod);
        $this->assertSame([], $updater->statusUpdates);
    }

    public function test_setting_off_preserves_current_name_and_marks_files_processed(): void
    {
        $updater = new RecordingDescriptiveTitleUpdater;
        $service = new TestableDescriptiveTitleNameFixingService($updater, false);

        $service->processFiles($this->obfuscatedRelease(), [
            (object) ['textstring' => 'Film ;-)/SupergirlPerv.avi'],
        ]);

        $this->assertNull($updater->descriptiveName);
        $this->assertNull($updater->ordinaryName);
        $this->assertSame([['proc_files', 1, 33751]], $updater->statusUpdates);
    }

    public function test_folder_fallback_cannot_bypass_the_guard_when_setting_is_off(): void
    {
        $updater = new RecordingDescriptiveTitleUpdater;
        $service = new TestableDescriptiveTitleNameFixingService($updater, false);

        $service->processFiles($this->obfuscatedRelease(), [
            (object) ['textstring' => 'Behind The Scenes Featurette (4k).mp4'],
        ]);

        $this->assertNull($updater->ordinaryName);
        $this->assertNull($updater->descriptiveName);
        $this->assertSame([['proc_files', 1, 33751]], $updater->statusUpdates);
    }

    public function test_junk_video_names_are_skipped_and_marked_processed(): void
    {
        $updater = new RecordingDescriptiveTitleUpdater;
        $service = new TestableDescriptiveTitleNameFixingService($updater, true);

        $service->processFiles($this->obfuscatedRelease(), array_map(
            static fn (string $name): object => (object) ['textstring' => $name],
            ['video1.mp4', 'Movie 2.mkv', 'VTS_01_1.VOB', 'sample.mp4', 'abcdef0123456789abcdef0123456789.mp4']
        ));

        $this->assertNull($updater->descriptiveName);
        $this->assertSame([['proc_files', 1, 33751]], $updater->statusUpdates);
    }

    private function obfuscatedRelease(): object
    {
        return (object) [
            'releases_id' => 33751,
            'searchname' => '(Els1212) [02/23] - "CQPVTOVKUDJVGELG.part01.rar"',
            'categories_id' => Category::OTHER_HASHED,
        ];
    }
}

class TestableDescriptiveTitleNameFixingService extends NameFixingService
{
    public function __construct(ReleaseUpdateService $updater, bool $enabled)
    {
        $this->updateService = $updater;
        $this->fileExtractor = new FileNameExtractor;
        $this->fileNameCleaner = new FileNameCleaner;
        $this->filePrioritizer = new FilePrioritizer;
        $this->descriptiveTitleRenameEnabled = $enabled;
    }

    /**
     * @param  list<object>  $files
     */
    public function processFiles(object $release, array $files): void
    {
        $this->processFileCandidates($release, $files, true, true, false, false, false);
    }

    protected function preDbFileCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        return false;
    }
}

class RecordingDescriptiveTitleUpdater extends ReleaseUpdateService
{
    public ?string $descriptiveName = null;

    public ?string $descriptiveMethod = null;

    public ?string $ordinaryName = null;

    /**
     * @var list<array{string, int, int}>
     */
    public array $statusUpdates = [];

    public function __construct()
    {
        $this->matched = false;
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
        if (! $descriptiveTitleCandidate) {
            $this->ordinaryName = $name;

            return;
        }

        $this->descriptiveName = $name;
        $this->descriptiveMethod = $method;
        $this->matched = true;
    }

    public function updateSingleColumn(string $column, int $status, int $id): void
    {
        $this->statusUpdates[] = [$column, $status, $id];
    }

    public function reset(): void
    {
        $this->matched = false;
    }

    public function incrementChecked(): void
    {
        $this->checked++;
    }
}
