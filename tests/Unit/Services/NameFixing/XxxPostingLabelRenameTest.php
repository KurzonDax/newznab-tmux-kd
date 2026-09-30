<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Category;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\ReleaseUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Under XXX, a descriptive video filename may replace an opaque posting label
 * that is not recognised as a hash. The real updater decides.
 */
class XxxPostingLabelRenameTest extends TestCase
{
    private const SUBJECT = 'Exampleab12 - [03/40] - "Exampleab12.part02.rar"';

    private const AFTERNOON = 'Example Studio - Afternoon Feature (2026-01-10).mp4';

    private const EVENING = 'Example Studio - Extended Evening Feature (2026-01-11).mp4';

    private const FILENAME_METHODS = ['fileCheck: Descriptive title', 'RarInfo: Descriptive title'];

    #[DataProvider('xxxCategories')]
    public function test_a_descriptive_video_filename_replaces_an_opaque_label_in_every_xxx_category(int $category): void
    {
        foreach (['bare' => 'Exampleab12', 'subject' => self::SUBJECT] as $form => $label) {
            foreach (self::FILENAME_METHODS as $method) {
                $this->assertSame(
                    self::EVENING,
                    $this->firstAccepted($label, $category, [self::AFTERNOON, self::EVENING], $method),
                    "{$form} label in category {$category} via {$method}",
                );
            }
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function xxxCategories(): array
    {
        $categories = [];
        foreach ((new ReflectionClass(Category::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'XXX_') && is_int($value)) {
                $categories[$name] = [$value];
            }
        }

        return $categories;
    }

    public function test_the_exception_changes_nothing_outside_xxx(): void
    {
        $expected = [
            Category::MOVIE_HD => false,
            Category::TV_HD => false,
            Category::MUSIC_MP3 => false,
            Category::PC_0DAY => false,
            Category::BOOKS_EBOOK => false,
            Category::GAME_OTHER => false,
            Category::OTHER_MISC => true,
            Category::OTHER_HASHED => true,
        ];

        foreach ($expected as $category => $accepted) {
            foreach (['Exampleab12', self::SUBJECT] as $label) {
                $this->assertSame(
                    $accepted ? self::EVENING : null,
                    $this->firstAccepted($label, $category, [self::AFTERNOON, self::EVENING]),
                    "{$label} in category {$category}",
                );
            }
        }
    }

    public function test_a_hashed_name_outside_xxx_is_still_recovered(): void
    {
        $this->assertSame(
            self::EVENING,
            $this->firstAccepted('5da7b5393d4f4445ac4db1ee8e95f567', Category::MOVIE_HD, [self::AFTERNOON, self::EVENING]),
        );
    }

    public function test_a_descriptive_current_title_keeps_its_protection(): void
    {
        $this->assertNull($this->firstAccepted(
            'Example Studio - Evening Feature (2026)',
            Category::XXX_X264,
            [self::AFTERNOON, self::EVENING],
        ));
    }

    public function test_junk_sample_and_hashed_video_names_gain_nothing(): void
    {
        $this->assertNull($this->firstAccepted(
            'Exampleab12',
            Category::XXX_X264,
            ['video1.mp4', 'sample.mp4', 'abcdef0123456789abcdef0123456789.mp4'],
        ));
    }

    public function test_a_promotional_video_filename_is_still_rejected(): void
    {
        $this->assertNull($this->firstAccepted(
            'Exampleab12',
            Category::XXX_X264,
            ['example.org - HEVC x265 Porn Downloads.mp4'],
        ));
    }

    public function test_the_exception_is_not_available_to_other_sources(): void
    {
        $title = 'Example Studio - Extended Evening Feature (2026-01-11)';
        $attempts = [
            'media title' => [$title, ReleaseUpdateService::DESCRIPTIVE_MEDIA_TITLE_METHOD, 'Mediainfo, ', true],
            'ordinary filename method' => [self::EVENING, 'fileCheck: Folder name', 'Filenames, ', false],
            'undeclared filename candidate' => [self::EVENING, 'fileCheck: Descriptive title', 'Filenames, ', false],
            'nfo' => [self::EVENING, 'fileCheck: Descriptive title', 'NFO, ', true],
        ];

        foreach ($attempts as $source => [$name, $method, $type, $descriptive]) {
            $updater = new ReleaseUpdateService;
            $updater->updateRelease(
                $this->release('Exampleab12', Category::XXX_X264, [self::EVENING]),
                $name,
                $method,
                false,
                $type,
                true,
                false,
                0,
                $descriptive,
            );

            $this->assertFalse($updater->matched, $source);
        }
    }

    public function test_an_episode_named_video_among_others_is_accepted_only_under_the_exception(): void
    {
        $files = ['Example Studio - S01E02 - Evening Feature.mp4', 'Example Studio - S01E05 - Morning Feature.mp4'];

        $this->assertContains($this->firstAccepted('Exampleab12', Category::XXX_X264, $files), $files);
        $this->assertNull($this->firstAccepted('Exampleab12', Category::MOVIE_HD, $files));
        // Categories that already allow a descriptive filename keep the episode guard.
        $this->assertNull($this->firstAccepted('Exampleab12', Category::OTHER_MISC, $files));
    }

    /**
     * Try each file in the existing priority order and stop at the first the updater accepts.
     *
     * @param  list<string>  $files
     */
    private function firstAccepted(
        string $currentName,
        int $category,
        array $files,
        string $method = 'fileCheck: Descriptive title',
    ): ?string {
        $files = (new FilePrioritizer)->prioritizeForMatching($files);
        $updater = new ReleaseUpdateService;
        foreach ($files as $file) {
            $updater->updateRelease(
                $this->release($currentName, $category, $files),
                $file,
                $method,
                false,
                'Filenames, ',
                true,
                false,
                0,
                true,
            );
            if ($updater->matched) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $files
     */
    private function release(string $currentName, int $category, array $files): object
    {
        return (object) [
            'releases_id' => 1,
            'predb_id' => 0,
            'categories_id' => $category,
            'name' => self::SUBJECT.' yEnc',
            'searchname' => $currentName,
            'relsize' => 10000,
            'knownFiles' => array_map(static fn (string $file): array => ['name' => $file, 'size' => 3000], $files),
        ];
    }
}
