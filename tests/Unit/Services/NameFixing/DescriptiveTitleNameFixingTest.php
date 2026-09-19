<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ObfuscationRecovery\RecoveryNameEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param  list<array{textstring: string, size?: int}>  $files
     * @param  array{}|array{string, string}  $expected
     */
    #[DataProvider('fileNameEvidence')]
    public function test_fallback_file_name_must_stand_for_the_release(string $subject, int $size, array $files, array $expected, bool $firstOnly): void
    {
        $updater = new RecordingDescriptiveTitleUpdater;
        $service = new TestableDescriptiveTitleNameFixingService($updater, false);
        $release = (object) [
            'releases_id' => 1, 'name' => $subject, 'searchname' => $subject,
            'categories_id' => Category::OTHER_MISC, 'relsize' => $size,
        ];

        $service->processFiles($release, array_map(static fn (array $file): object => (object) $file, $files));

        if ($expected === []) {
            $this->assertSame([], $updater->ordinaryCalls);
            $this->assertSame([['proc_files', 1, 1]], $updater->statusUpdates);
        } elseif ($firstOnly) {
            $this->assertSame($expected, $updater->ordinaryCalls[0]);
        } else {
            $this->assertSame([$expected], $updater->ordinaryCalls);
        }
    }

    /** @return array<string, array{string, int, list<array{textstring: string, size?: int}>, array{}|array{string, string}, bool}> */
    public static function fileNameEvidence(): array
    {
        return [
            'D1' => ['[27/49] "Visible Release Studio Portable v2026.0.0.71.part26.rar" yEnc', 1_000_000_000, [['textstring' => 'App/Visible Studio/crashpad_wer.dll', 'size' => 24_072]], [], false],
            'D2' => ['(Visible Release Suite 2026 v27.1.0.129 (x64) Multilingual)[32/33] - "Visible Release Suite 2026 v27.1.0.129 (x64) Multilingual.part32.rar" yEnc', 3_000_000_000, [['textstring' => 'Visible Release Suite 2026 v27.1.0.129 (x64) Multilingual/VisibleReleaseSuite2026Installer_MF01/autorun.inf', 'size' => 50]], [], false],
            'D3' => ['[03/12] - "5da7b5393d4f4445ac4db1ee8e95f567.part1.rar" yEnc', 150_000_000, [['textstring' => 'Visible Artist - Visible Album (2026) [MP3]\\16. Visible Song.mp3', 'size' => 3_600_000]], [], false],
            'D4' => ['"5da7b5393d4f4445ac4db1ee8e95f567.part1.rar" yEnc', 90_000_000, [['textstring' => 'Visible Folder - 2026-08-14/2026-08-13 21_29_28-Visible Folder - Viewer.jpg', 'size' => 9_000]], [], false],
            'D5' => ['[1/22] 5da7b5393d4f4445ac4db1ee8e95f567 yEnc', 900_000_000, [['textstring' => 'Visible.Release.v0.9.1.7/EnginePlayer.dll', 'size' => 7_000_000]], [], false],
            'D6' => ['[01/24] - "VisibleStudio.13.10.18.Visible.Performer.XXX.720p.MP4-GROUP.part01.rar" yEnc', 1_000_000_000, [['textstring' => 'vis.13.10.18.visible.performer.mp4', 'size' => 880_000_000]], [], false],
            'D7' => ['[01/71] - "Visible Release Editor 2026 (v27.8.0.13) Multilingual.part01.rar" yEnc', 4_900_000_000, [['textstring' => 'Visible Release Editor 2026 (v27.8.0.13) Multilingual/Visible.Release.Editor.2026.u8.Multilingual.iso', 'size' => 4_700_000_000]], [], false],
            'D8' => ['[002/111] "visible release dvd retail.part001.rar" yEnc', 7_800_000_000, [['textstring' => 'New folder/Visible Release 2016.iso', 'size' => 7_079_985_152]], [], false],
            'D9' => ['[001/112] "5da7b5393d4f4445ac4db1ee8e95f567.par2" yEnc', 5_500_000_000, [['textstring' => 'Visible Release-3.bin', 'size' => 948_573_977]], [], false],
            'D10' => ['5da7b5393d4f4445ac4db1ee8e95f567 - [17/33] - "5da7b5393d4f4445ac4db1ee8e95f567.part16.rar" yEnc', 4_600_000_000, [['textstring' => 'Visible Show season 4 DVD 2.iso']], [], false],
            'D11' => ['"5da7b5393d4f4445ac4db1ee8e95f567.part16.rar" yEnc', 1_000_000_000, [['textstring' => 'Visible Show season 4 DVD 2.iso', 'size' => 799_999_999]], [], false],
            'D12' => ['5da7b5393d4f4445ac4db1ee8e95f567.part01.rar - [02/95] yEnc', 5_100_000_000, [['textstring' => 'Visible Release 2 (2012).nl.srt', 'size' => 55_020]], [], false],
            'K1' => ['"5da7b5393d4f4445ac4db1ee8e95f567.part16.rar" yEnc', 1_000_000_000, [['textstring' => 'Visible Show season 4 DVD 2.iso', 'size' => 800_000_000]], ['fileCheck: Folder name', 'Visible Show season 4 DVD 2.iso'], false],
            'K2' => ['(poster) [03/37] - "5da7b5393d4f4445ac4db1ee8e95f567.part02.rar" yEnc', 1_633_000_000, [['textstring' => 'Film/1.jpg', 'size' => 83_308], ['textstring' => 'Film/Visible.Release.16.XXX.CD1.avi', 'size' => 726_929_408], ['textstring' => 'Film/Visible.Release.16.XXX.CD2.avi', 'size' => 730_081_280]], ['fileCheck: Folder name', 'Visible.Release.16.XXX.CD1.avi'], true],
            'K3' => ['"5da7b5393d4f4445ac4db1ee8e95f567.part10.rar" yEnc', 1_600_000_000, [['textstring' => 'Visible Release 3 - cd1.avi', 'size' => 733_767_680]], ['fileCheck: Folder name', 'Visible Release 3 - cd1.avi'], false],
            'K4' => ['[11/77] - "group-visible.release.iso" yEnc', 7_338_673_920, [['textstring' => 'group-visible.release.iso', 'size' => 3_952_887_808]], ['fileCheck: Folder name', 'group-visible.release.iso'], false],
            'K5' => ['VisibleMix1294 - [07/16] - "Visible Mix Show 1294 (10-09-2026).part6.rar" yEnc', 1_457_065_979, [['textstring' => 'Visible Mix Show 1294 (10-09-2026)/Visible Artist - Visible Mix Show 1294.mp3', 'size' => 299_751_454]], ['fileCheck: Folder name', 'Visible Artist - Visible Mix Show 1294.mp3'], false],
            'K6' => ['AP - "Visible Release Title.part142.rar" yEnc', 7_600_000_000, [['textstring' => 'XXX DVDISO - Visible Release Title (2007).iso', 'size' => 7_465_795_584]], ['fileCheck: Folder name', 'XXX DVDISO - Visible Release Title (2007)'], false],
            'K7' => ['Hosted by http://avchd-visible.example - "5da7b5393d4f4445ac4db1ee8e95f567.part02.rar" yEnc', 8_600_000_000, [['textstring' => 'Visible Release (2011) 1080p AVCHD.iso', 'size' => 8_423_473_152]], ['fileCheck: Folder name', 'Visible Release (2011) 1080p AVCHD'], false],
            'K8' => ['"5da7b5393d4f4445ac4db1ee8e95f567.part1.rar" yEnc', 9_000_000_000, [['textstring' => 'Visible.Show.S01E02.1080p.WEB-DL.DDP5.1.H.264-GROUP.mkv', 'size' => 10]], ['fileCheck: TV SxxExx with quality', 'Visible.Show.S01E02.1080p.WEB-DL.DDP5.1.H.264-GROUP'], false],
            'K9' => ['5da7b5393d4f4445ac4db1ee8e95f567.part01.rar - [02/95] yEnc', 5_100_000_000, [['textstring' => 'Visible Release 2 (2012).nl.srt', 'size' => 55_020], ['textstring' => 'Visible Release 2 (2012).mkv', 'size' => 4_971_475_966]], ['fileCheck: Folder name', 'Visible Release 2 (2012).nl'], true],
        ];
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
        $this->predbMatchSelector = new PredbMatchSelector($this->fileNameCleaner);
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

    /** @var list<array{string, string}> */
    public array $ordinaryCalls = [];

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
        ?RecoveryNameEvidence $recoveryEvidence = null,
    ): void {
        if (! $descriptiveTitleCandidate) {
            $this->ordinaryName = $name;
            $this->ordinaryCalls[] = [$method, $name];

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
