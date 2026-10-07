<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\Categorizers\GroupNameCategorizer;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\TvPipe;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategorizeBookTest extends TestCase
{
    private BookCategorizer $bookCategorizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookCategorizer = new BookCategorizer;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function validBookProvider(): array
    {
        return [
            'comic cbz' => ['Batman.Vol.3.Issue.145.2024.DC.Comics.CBZ', Category::BOOKS_COMICS],
            'technical publisher ebook' => ['The.Pragmatic.Programmer.20th.Anniversary.Edition.OReilly.2019.EPUB', Category::BOOKS_TECHNICAL],
            'magazine issue date' => ['Vegan_Food_and_Living_Monthly_May_2026_Issue_352', Category::BOOKS_MAGAZINES],
            'magazine issue with year' => ['History of War - Issue 158, 2026', Category::BOOKS_MAGAZINES],
            'mcn magazine pattern' => ['MCN.April.22.2026.HYBRID.MAGAZINE.eBook-21A1', Category::BOOKS_MAGAZINES],
            'ebook pdf with book context' => ['George.Orwell.1984.Novel.PDF', Category::BOOKS_EBOOK],
            'ebook part rar style after normalize' => ['Harry Styles Songbook - 1st Edition 2026', Category::BOOKS_EBOOK],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonBookProvider(): array
    {
        return [
            'foxit software with pdf name' => ['Foxit.PDF.Editor.Pro.14.0.4.33508.Multilingual'],
            'movie remux release' => ['The.Red.Ball.Express.1952.BR.Remux.AVC-d3g'],
            'python software runtime' => ['Python.3.12.2.Setup.x64'],
            'video training course' => ['Udemy.JavaScript.Masterclass.2026.1080p'],
            'font pack release' => ['Professional.Font.Pack.2026.OTF.Collection'],
            'music-like release' => ['Pop_Classics_6.26.part1'],
        ];
    }

    #[DataProvider('validBookProvider')]
    public function test_book_categorizer_detects_valid_books(string $name, int $expectedCategory): void
    {
        $context = new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: '',
            poster: ''
        );

        $result = $this->bookCategorizer->categorize($context);

        $this->assertTrue($result->isSuccessful(), "Expected a valid book match for: {$name}");
        $this->assertSame($expectedCategory, $result->categoryId, "Wrong book category for: {$name}");
    }

    #[DataProvider('nonBookProvider')]
    public function test_book_categorizer_rejects_non_books(string $name): void
    {
        $context = new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: '',
            poster: ''
        );

        $this->assertTrue(
            $this->bookCategorizer->shouldSkip($context) || ! $this->bookCategorizer->categorize($context)->isSuccessful(),
            "Expected non-book release to be skipped or unmatched: {$name}"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function magazineShapeProvider(): array
    {
        return [
            'word with month and year' => ['Magazine - Women’s Health UK – October 2026 nzb', 'magazine_word'],
            'word with month and year and no dash' => ['Mece Magazine July 2026', 'magazine_word'],
            'word with volume number' => ['Magazine - Australian Guitar – Volume 169 2026', 'magazine_word'],
            'dash month day year' => ['Usa California Cabernets New classics Wine Spectator - November 15 2026', 'magazine_dated_title'],
            'dash month range year with container token' => ['Good Housekeeping USA - September-October 2026 nzb', 'magazine_dated_title'],
            'dash season year' => ['USA Cooking Light - 30-Minute Meals - Fall 2026', 'magazine_dated_title'],
            'dash day month year' => ['Amateur Photographer – 11 August 2026', 'magazine_dated_title'],
            'dash day month range year' => ['The Week UK - 26 September - 2 October 2026', 'magazine_dated_title'],
            'known title with month day year' => ['People USA Brad Angelina Kate William The Unstoppable September 28 2026', 'magazine_title_dated'],
        ];
    }

    #[DataProvider('magazineShapeProvider')]
    public function test_magazine_shaped_names_are_magazines(string $name, string $expectedMatchedBy): void
    {
        $result = $this->bookCategorizer->categorize($this->context($name));

        $this->assertSame(
            [Category::BOOKS_MAGAZINES, 0.9, $expectedMatchedBy],
            [$result->categoryId, $result->confidence, $result->matchedBy],
            "Wrong magazine result for: {$name}",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notMagazineShapeProvider(): array
    {
        return [
            'adult publication marker after the extractor dropped the plus' => ['Magazine 18 - FHM South Africa – September 2026'],
            'erotic magazine' => ['Erotic Magazine - NUTS Magazine The Girls of NUTS Summer SPECIAL 2026 nzb'],
            'known title without a month and year' => ['People USA Special Edition'],
            'word with a bare year in a film name' => ['Magazine Dreams 2023 x265 HEVC-PSA'],
            'word with a bare year in an album name' => ['Magazine - Secondhand Daylight 1979'],
            'word with bracketed years' => ['Magazine - Real Life (1978) [Remastered 2007]'],
        ];
    }

    #[DataProvider('notMagazineShapeProvider')]
    public function test_names_outside_the_magazine_shapes_do_not_match_them(string $name): void
    {
        $result = $this->bookCategorizer->categorize($this->context($name));

        $this->assertNotContains(
            $result->matchedBy,
            ['magazine_word', 'magazine_dated_title', 'magazine_title_dated'],
            "Unexpected magazine shape match for: {$name}",
        );
    }

    public function test_audiobook_stays_in_music_category_in_pipeline(): void
    {
        $passable = $this->runPipes(
            'Brandon.Sanderson.Wind.And.Truth.Audiobook.Unabridged.M4B',
            '',
            [new MusicPipe, new BookPipe]
        );

        $this->assertSame(Category::MUSIC_AUDIOBOOK, $passable->bestResult->categoryId);
    }

    public function test_non_book_in_ebook_group_prefers_pc_category_over_group_book_hint(): void
    {
        $passable = $this->runPipes(
            'Foxit.PDF.Editor.Pro.v14.0.4.Portable.x64.Multilingual',
            'alt.binaries.e-book',
            [new GroupNamePipe, new PcPipe, new BookPipe]
        );

        $this->assertSame(Category::PC_0DAY, $passable->bestResult->categoryId);
    }

    public function test_dated_magazine_outranks_a_group_name_hint(): void
    {
        $passable = $this->runPipes(
            'Usa California Cabernets New classics Wine Spectator - November 15 2026',
            'alt.binaries.games.xbox',
            [new GroupNamePipe, new BookPipe]
        );

        $this->assertSame(Category::BOOKS_MAGAZINES, $passable->bestResult->categoryId);
    }

    public function test_dated_sport_episode_keeps_its_tv_result_over_the_magazine_shape(): void
    {
        $passable = $this->runPipes(
            'WWE Raw - September 28 2026',
            '',
            [new TvPipe, new BookPipe]
        );

        $this->assertSame(
            [Category::TV_SPORT, 'sport'],
            [$passable->bestResult->categoryId, $passable->bestResult->matchedBy],
        );
        $this->assertSame('earlier_content_match', $passable->allResults['Book']['suppressed_by']);
    }

    public function test_seasonal_dj_mix_keeps_its_music_result_over_the_magazine_shape(): void
    {
        $passable = $this->runPipes(
            'Hed Kandi - Summer 2024',
            'alt.binaries.sounds.mp3',
            [new MusicPipe, new BookPipe]
        );

        $this->assertSame(
            [Category::MUSIC_OTHER, 'music_dj'],
            [$passable->bestResult->categoryId, $passable->bestResult->matchedBy],
        );
        $this->assertSame('earlier_content_match', $passable->allResults['Book']['suppressed_by']);
    }

    public function test_group_name_book_confidence_is_reduced_to_point_five(): void
    {
        $categorizer = new GroupNameCategorizer;
        $context = new ReleaseContext(
            releaseName: 'Unknown.Upload.Name',
            groupId: 0,
            groupName: 'alt.binaries.ebooks.misc',
            poster: ''
        );

        $result = $categorizer->categorize($context);

        $this->assertSame(Category::BOOKS_EBOOK, $result->categoryId);
        $this->assertSame(0.5, $result->confidence);
        $this->assertSame('group_name_book', $result->matchedBy);
    }

    private function context(string $releaseName): ReleaseContext
    {
        return new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: '',
            poster: ''
        );
    }

    /**
     * @param  list<object>  $pipes
     */
    private function runPipes(string $releaseName, string $groupName, array $pipes): CategorizationPassable
    {
        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: $groupName,
            poster: ''
        );

        $passable = new CategorizationPassable($context, debug: true);
        foreach ($pipes as $pipe) {
            $passable = $pipe->handle($passable, fn ($p) => $p);
        }

        return $passable;
    }
}
