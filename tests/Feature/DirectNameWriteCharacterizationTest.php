<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionReconciliation\ArtifactReleaseUpdate;
use App\Services\CollectionReconciliation\PostingPublication;
use App\Services\MusicIdentity\Rename\MusicRenameProjection;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryNaming;
use App\Services\ReleaseImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Records what the name writers other than updateRelease() write today, the five trust
 * predicates, and the additional-processing rename quirks (issue #1120, Section 1).
 */
class DirectNameWriteCharacterizationTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    private const string OBFUSCATED = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    private const string TITLE = 'Some.Movie.2024.1080p.BluRay.x264-GROUP';

    private const array CLEARED = [
        'videos_id' => 0,
        'tv_episodes_id' => 0,
        'movieinfo_id' => null,
        'imdbid' => null,
        'musicinfo_id' => null,
        'consoleinfo_id' => null,
        'bookinfo_id' => null,
        'anidbid' => null,
        'gamesinfo_id' => 0,
    ];

    private ReleaseUpdateService $updates;

    /** @var list<int> */
    private array $synchronized = [];

    protected function bootstrapSettings(): array
    {
        return ['categorizeforeign' => '0', 'catwebdl' => '1'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->createNameWriteTables();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $this->updates = new ReleaseUpdateService(searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId): void {
                $this->synchronized[] = $releaseId;
            },
        ));
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_attaching_a_predb_id_keeps_the_name_and_trusts_it(): void
    {
        $id = $this->linkedRelease(1, ['searchname' => self::TITLE, 'categories_id' => Category::MOVIE_HD]);

        $this->assertTrue($this->renames(fn (ReleaseUpdateService $updates) => $updates->attachPredbId($id, 77)));

        $this->assertWritten($id, self::TITLE, trusted: 1, source: 'PreDB exact', status: true, procColumns: [], predb: 77,
            links: self::CLEARED, category: Category::MOVIE_HD);
        $this->assertEvent($id, self::TITLE, self::TITLE, Category::MOVIE_HD, null);
        $this->assertFalse($this->rename($this->snapshot($id), 'Other.Movie.2024.1080p.BluRay.x264-GROUP', 'PAR2, ', 'preDB: Match', 78));
    }

    public function test_an_audio_tag_rename_is_trusted_sets_proc_pp_and_files_the_category(): void
    {
        $id = $this->linkedRelease(1);

        $this->assertSame('Artist - Album (2024)', $this->updates->renameFromAudioTags($id, 'Artist - Album (2024)', Category::MUSIC_MP3));

        $this->assertWritten($id, 'Artist - Album (2024)', trusted: 1, source: 'Audio tags', status: true, procColumns: ['proc_pp'], predb: 0,
            links: self::CLEARED, category: Category::MUSIC_MP3);
        $this->assertEvent($id, self::OBFUSCATED, 'Artist - Album (2024)', Category::OTHER_HASHED, Category::MUSIC_MP3);
    }

    /** @return iterable<string, array{string}> */
    public static function unguardedTitles(): iterable
    {
        yield 'promotional' => ['Downloaded from example.org'];
        yield 'implausible' => ['Some Movie Night'];
    }

    #[DataProvider('unguardedTitles')]
    public function test_tag_music_and_book_renames_skip_the_title_guards(string $title): void
    {
        $tags = $this->linkedRelease(1);
        $music = $this->linkedRelease(2, ['categories_id' => Category::MUSIC_MP3]);
        $book = $this->linkedRelease(3, ['categories_id' => Category::BOOKS_EBOOK]);

        $this->assertSame($title, $this->updates->renameFromAudioTags($tags, $title, Category::MUSIC_MP3));
        $this->assertSame($title, $this->updates->renameFromMusicIdentity($music, $title));
        $this->updates->renameFromBookMetadata($book, $title);

        foreach ([$tags, $music, $book] as $id) {
            $this->assertSame($title, $this->releaseRow($id)['searchname']);
        }
    }

    public function test_a_musicbrainz_rename_keeps_the_music_link_and_category(): void
    {
        $id = $this->linkedRelease(1, ['categories_id' => Category::MUSIC_MP3]);

        $this->updates->renameFromMusicIdentity($id, 'Artist - Album (2024)');

        $this->assertWritten($id, 'Artist - Album (2024)', trusted: 1, source: 'MusicBrainz', status: true, procColumns: ['proc_pp'], predb: 0,
            links: [...self::CLEARED, 'musicinfo_id' => 44], category: Category::MUSIC_MP3);
        $this->assertEvent($id, self::OBFUSCATED, 'Artist - Album (2024)', Category::MUSIC_MP3, Category::MUSIC_MP3);
    }

    public function test_a_book_title_rename_keeps_the_book_link_and_stays_untrusted(): void
    {
        $id = $this->linkedRelease(1, ['categories_id' => Category::BOOKS_EBOOK]);

        $this->updates->renameFromBookMetadata($id, 'Author - Book Title (2024)');

        $this->assertWritten($id, 'Author - Book Title (2024)', trusted: 0, source: 'Book title', status: true, procColumns: [], predb: 0,
            links: [...self::CLEARED, 'bookinfo_id' => 46], category: Category::BOOKS_EBOOK);
        $this->assertEvent($id, self::OBFUSCATED, 'Author - Book Title (2024)', Category::BOOKS_EBOOK, null);
    }

    public function test_attaching_an_srrdb_match_trusts_the_name_and_stores_the_imdb_id(): void
    {
        $id = $this->linkedRelease(1, ['searchname' => self::TITLE, 'categories_id' => Category::MOVIE_HD]);

        $this->assertTrue($this->renames(fn (ReleaseUpdateService $updates) => $updates->attachSrrdbMatch($id, 77, '7654321')));

        $this->assertWritten($id, self::TITLE, trusted: 1, source: 'SRRDB', status: true, procColumns: ['proc_srrdb'], predb: 77,
            links: [...self::CLEARED, 'imdbid' => 7654321], category: Category::MOVIE_HD);
    }

    public function test_restoring_music_identity_values_writes_them_verbatim(): void
    {
        $id = $this->linkedRelease(1, ['searchname' => 'Artist - Album (2024)', 'categories_id' => Category::MUSIC_MP3,
            'is_trusted_name' => 1, 'isrenamed' => 1, 'name_source' => 'MusicBrainz', 'proc_pp' => 1]);

        $this->assertTrue($this->updates->restoreFromMusicIdentity($id, [
            'searchname' => 'Artist - Album', 'name_source' => null, 'is_trusted_name' => 0, 'isrenamed' => 0,
            'predb_id' => 5, 'videos_id' => 7, 'categories_id' => Category::MUSIC_OTHER,
        ]));

        $row = $this->releaseRow($id);
        $this->assertSame(Release::searchNameValues('Artist - Album'), array_intersect_key($row, Release::searchNameValues('x')));
        $this->assertNull($row['name_source']);
        $this->assertSame([0, 0, 5, 7, Category::MUSIC_OTHER, 1], [(int) $row['is_trusted_name'], (int) $row['isrenamed'],
            (int) $row['predb_id'], (int) $row['videos_id'], (int) $row['categories_id'], (int) $row['proc_pp']]);
        $this->assertEvent($id, 'Artist - Album (2024)', 'Artist - Album', Category::MUSIC_MP3, Category::MUSIC_OTHER);
        $this->assertSame([$id], $this->synchronized);
    }

    public function test_the_admin_edit_records_a_typed_name_as_manual(): void
    {
        Search::shouldReceive('updateRelease')->twice();
        $id = $this->linkedRelease(1, ['name_source' => 'PAR2', 'is_trusted_name' => 1, 'isrenamed' => 1]);

        ProductionTables::fromAuthority()->create('movieinfo', ['id', 'imdbid']);
        Release::updateRelease($id, 'subject', self::OBFUSCATED, 'poster', Category::MOVIE_HD, 1, 0, 1000, now(), now(), 41, 42, '1234567', 47);
        $this->assertSame('PAR2', $this->releaseRow($id)['name_source']);

        Release::updateRelease($id, 'subject', self::TITLE, 'poster', Category::MOVIE_HD, 1, 0, 1000, now(), now(), 41, 42, '1234567', 47);
        $row = $this->releaseRow($id);
        $this->assertSame(Release::MANUAL_NAME_SOURCE, $row['name_source']);
        $this->assertSame(Release::searchNameValues(self::TITLE), array_intersect_key($row, Release::searchNameValues('x')));
        $this->assertSame([1, 1, Category::MOVIE_HD], [(int) $row['is_trusted_name'], (int) $row['isrenamed'], (int) $row['categories_id']]);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_requeue_failed_books_normalizes_an_obfuscated_book_name_in_place(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);
        $id = $this->linkedRelease(1, ['name' => 'N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"',
            'searchname' => 'N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"', 'categories_id' => Category::BOOKS_EBOOK,
            'bookinfo_id' => -2, 'name_source' => 'PAR2', 'is_trusted_name' => 1, 'predb_id' => 9]);

        $this->artisan('books:requeue-failed', ['--normalize-obfuscated-all' => true])
            ->expectsOutputToContain('Requeued 1 failed book release(s). Normalized 1 searchname value(s). Skipped: 0.')
            ->assertSuccessful();

        $row = $this->releaseRow($id);
        $this->assertSame(Release::searchNameValues('History of War - Issue 158, 2026'), array_intersect_key($row, Release::searchNameValues('x')));
        $this->assertSame(['PAR2', 1, 9, 1, Category::BOOKS_EBOOK], [$row['name_source'], (int) $row['is_trusted_name'],
            (int) $row['predb_id'], (int) $row['isrenamed'], (int) $row['categories_id']]);
        $this->assertSame($this->links(['bookinfo_id' => null]), $this->linksOf($id));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_fix_nzbsplit_unwraps_the_name_and_categorizes_it_inline(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);
        $wrapped = 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065" yEnc(1';
        $id = $this->linkedRelease(1, ['name' => $wrapped, 'searchname' => $wrapped, 'name_source' => 'PAR2', 'is_trusted_name' => 1, 'predb_id' => 9]);
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldReceive('determineCategory')->once()->andReturn(['categories_id' => Category::TV_HD]);
        $this->app->instance(CategorizationService::class, $categorization);

        $this->artisan('releases:fix-nzbsplit')
            ->expectsOutputToContain('Updated 1 NZBSPLIT-wrapped releases.')
            ->assertSuccessful();

        $row = $this->releaseRow($id);
        $unwrapped = (string) $row['searchname'];
        $this->assertStringNotContainsString('NZBSPLIT', $unwrapped);
        $this->assertSame($unwrapped, $row['name']);
        $this->assertSame(Release::searchNameValues($unwrapped), array_intersect_key($row, Release::searchNameValues('x')));
        $this->assertSame(['PAR2', 1, 9, 1, 1, Category::TV_HD], [$row['name_source'], (int) $row['is_trusted_name'],
            (int) $row['predb_id'], (int) $row['isrenamed'], (int) $row['iscategorized'], (int) $row['categories_id']]);
        $this->assertSame($this->links([...self::CLEARED, 'movieinfo_id' => 43, 'bookinfo_id' => 0]), $this->linksOf($id));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function donorRows(): iterable
    {
        yield 'PreDB id' => [['predb_id' => 5], true];
        yield 'AniDB id' => [['anidbid' => 5], true];
        yield 'trusted name' => [['is_trusted_name' => 1], true];
        yield 'renamed only' => [['isrenamed' => 1, 'videos_id' => 5, 'movieinfo_id' => 5], false];
        yield 'nothing' => [[], false];
    }

    /** @param array<string, mixed> $columns */
    #[DataProvider('donorRows')]
    public function test_the_name_fixing_donor_predicate(array $columns, bool $donor): void
    {
        $id = $this->linkedRelease(1, ['anidbid' => null, 'predb_id' => 0, ...$columns]);
        DB::table('par_hashes')->insert(['releases_id' => $id, 'hash' => 'abc']);

        $this->assertSame($donor, (new NameFixingQueryService)->hashDonors(['abc']) !== []);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function identityRows(): iterable
    {
        foreach (['is_trusted_name' => 1, 'predb_id' => 5, 'movieinfo_id' => 5, 'imdbid' => '0000005', 'videos_id' => 5, 'tv_episodes_id' => 5] as $column => $value) {
            yield $column => [[$column => $value], true];
        }
        yield 'renamed, AniDB and music only' => [['isrenamed' => 1, 'anidbid' => 5, 'musicinfo_id' => 5], false];
    }

    /** @param array<string, mixed> $columns */
    #[DataProvider('identityRows')]
    public function test_the_bundle_trusted_identity_predicate(array $columns, bool $trusted): void
    {
        $id = $this->linkedRelease(1, ['videos_id' => 0, 'tv_episodes_id' => 0, 'movieinfo_id' => null, 'imdbid' => null, ...$columns]);
        $release = Release::query()->findOrFail($id);

        $this->assertSame($trusted, PostingPublication::hasTrustedIdentity($release));
        $this->assertSame(! $trusted, (new ArtifactReleaseUpdate('reconciliation', independentVideos: true))->allows($release));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function recoveryNameRows(): iterable
    {
        yield 'neutral' => [[], false];
        yield 'renamed' => [['isrenamed' => 1], true];
        yield 'trusted' => [['is_trusted_name' => 1], true];
        yield 'PreDB id' => [['predb_id' => 5], true];
        yield 'another name' => [['searchname' => 'Recovered.other'], true];
    }

    /** @param array<string, mixed> $columns */
    #[DataProvider('recoveryNameRows')]
    public function test_the_recovery_existing_name_predicate(array $columns, bool $existing): void
    {
        $identity = str_repeat('f', 40);
        $id = $this->linkedRelease(1, ['searchname' => 'Recovered.'.substr($identity, 0, 24), ...$columns]);

        $this->assertSame($existing, RecoveryNaming::hasExistingName(Release::query()->findOrFail($id), $identity));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function replaceableRows(): iterable
    {
        yield 'audio tags, trusted' => [['name_source' => 'Audio tags', 'is_trusted_name' => 1], true];
        yield 'untrusted' => [['name_source' => 'PAR2', 'is_trusted_name' => 0], true];
        yield 'manual, untrusted' => [['name_source' => 'Manual', 'is_trusted_name' => 0], false];
        yield 'trusted, no source' => [['name_source' => null, 'is_trusted_name' => 1], false];
        yield 'trusted' => [['name_source' => 'NFO', 'is_trusted_name' => 1], false];
    }

    /** @param array<string, scalar|null> $release */
    #[DataProvider('replaceableRows')]
    public function test_the_music_rename_replaceable_name_predicate(array $release, bool $replaceable): void
    {
        $projection = (new ReflectionClass(MusicRenameProjection::class))->newInstanceWithoutConstructor();

        $this->assertSame($replaceable, (new ReflectionMethod(MusicRenameProjection::class, 'nameIsReplaceable'))->invoke($projection, $release));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function labelRows(): iterable
    {
        yield 'neither' => [[], true];
        yield 'trusted' => [['is_trusted_name' => 1], false];
        yield 'renamed' => [['isrenamed' => 1], false];
    }

    /** @param array<string, mixed> $columns */
    #[DataProvider('labelRows')]
    public function test_a_reconciliation_label_replaces_only_an_untrusted_unrenamed_name(array $columns, bool $labelled): void
    {
        $id = $this->linkedRelease(1, ['videos_id' => 0, 'tv_episodes_id' => 0, 'movieinfo_id' => null, 'imdbid' => null,
            'name_source' => 'PAR2', ...$columns]);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="p" date="1" subject="&quot;a.mkv&quot; yEnc (1/1)">'
            .'<groups><group>alt.binaries.test</group></groups><segments><segment bytes="100" number="1">a@b</segment></segments></file></nzb>';

        (new ArtifactReleaseUpdate('reconciliation', label: 'Reconciled.Label'))->apply(Release::query()->findOrFail($id), $xml);

        $row = $this->releaseRow($id);
        $this->assertSame($labelled ? 'Reconciled.Label' : self::OBFUSCATED, $row['searchname']);
        if ($labelled) {
            $this->assertSame(['Reconciled.Label', 0, 0, 'PAR2'], [$row['name'], (int) $row['is_trusted_name'], (int) $row['isrenamed'], $row['name_source']]);
            $this->assertSame(Release::searchNameValues('Reconciled.Label'), array_intersect_key($row, Release::searchNameValues('x')));
        }
    }

    public function test_a_declined_nzbsplit_rename_still_reports_a_rename_and_sets_the_name_in_memory(): void
    {
        $id = $this->linkedRelease(1, ['name' => $this->nzbSplitSubject(), 'searchname' => self::TITLE]);
        $release = Release::query()->findOrFail($id);
        $context = new ReleaseProcessingContext($release);
        $this->assertTrue($this->rename($this->snapshot($id), 'Other.Movie.2024.1080p.BluRay.x264-GROUP', 'Filenames, ', 'fileCheck: Scene release with group'));

        $this->assertTrue($this->fileManager()->processReleaseNameFromNzbContents([['title' => $this->nzbSplitSubject()]], $context));

        $this->assertSame('Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)', $context->release->searchname);
        $this->assertSame('Other.Movie.2024.1080p.BluRay.x264-GROUP', $this->releaseRow($id)['searchname']);
    }

    public function test_one_additional_processing_run_renames_an_nzbsplit_release_once(): void
    {
        $id = $this->linkedRelease(1, ['name' => $this->nzbSplitSubject(), 'searchname' => $this->nzbSplitSubject()]);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail($id));
        $manager = $this->fileManager();

        $this->assertTrue($manager->processReleaseNameFromNzbContents([['title' => $this->nzbSplitSubject()]], $context));
        $nzbSplitName = (string) $this->releaseRow($id)['searchname'];
        $this->assertSame('Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)', $nzbSplitName);
        $this->assertSame([1, 'Filenames'], [(int) $this->releaseRow($id)['is_trusted_name'], $this->releaseRow($id)['name_source']]);

        $this->assertTrue((new ReflectionMethod(ReleaseFileManager::class, 'tryRenameFromRarFileNames'))
            ->invoke($manager, ['Other.Movie.2024.1080p.BluRay.x264-GROUP/other.movie.2024.1080p.bluray.x264-group.mkv'], $context));

        $this->assertSame($nzbSplitName, $this->releaseRow($id)['searchname']);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    private function nzbSplitSubject(): string
    {
        return 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065" yEnc(1';
    }

    private function fileManager(): ReleaseFileManager
    {
        $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();

        return new ReleaseFileManager(
            $config,
            $this->createStub(ReleaseImageService::class),
            $this->createStub(NfoService::class),
            $this->createStub(NzbService::class),
            $this->createStub(NameFixingService::class),
            $this->updates,
            new FileNameCleaner,
        );
    }

    /** Whether the call renamed the release, as the update service reports it. */
    private function renames(callable $call): bool
    {
        return $call($this->updates)->renamed;
    }

    /** Whether updateRelease() accepted the candidate from the call site's (type, method) labels. */
    private function rename(object $release, string $title, string $type, string $method, int $preId = 0): bool
    {
        $source = match ($type.$method) {
            'PAR2, preDB: Match' => NameSource::Par2PreDbMatch,
            'Filenames, fileCheck: Scene release with group' => NameSource::FileName,
        };

        return $this->renames(static fn (ReleaseUpdateService $updates) => $updates->updateRelease($release, $title, $method, true, $source, true, false, $preId));
    }

    private function snapshot(int $id): object
    {
        return (object) [...$this->releaseRow($id), 'releases_id' => $id, 'relsize' => $this->releaseRow($id)['size']];
    }

    /**
     * @param  list<string>  $procColumns
     * @param  array<string, mixed>  $links
     */
    private function assertWritten(int $id, string $title, int $trusted, ?string $source, bool $status, array $procColumns, int $predb, array $links, int $category): void
    {
        $row = $this->releaseRow($id);
        $this->assertSame(Release::searchNameValues($title), array_intersect_key($row, Release::searchNameValues('x')));
        $this->assertSame([$trusted, $source, $status ? 1 : 0, $status ? 1 : 0, $predb, $category], [
            (int) $row['is_trusted_name'], $row['name_source'], (int) $row['isrenamed'], (int) $row['iscategorized'], (int) $row['predb_id'], (int) $row['categories_id'],
        ]);
        foreach (['proc_pp', 'proc_par2', 'proc_nfo', 'proc_files', 'proc_xxx', 'proc_uid', 'proc_media_movie', 'proc_srr', 'proc_hash16k', 'proc_crc32', 'proc_srrdb'] as $column) {
            $this->assertSame(in_array($column, $procColumns, true) ? 1 : 0, (int) $row[$column], $column);
        }
        $this->assertSame($this->links($links), $this->linksOf($id));
        $this->assertSame([$id], $this->synchronized);
    }

    private function assertEvent(int $id, string $oldName, string $newName, int $oldCategory, ?int $override): void
    {
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        $event = Event::dispatched(ReleaseNameFixed::class)->first()[0];
        $this->assertSame([$id, $oldName, $newName, $oldCategory, $override], [
            $event->releaseId, $event->oldName, $event->newName, $event->oldCategoryId, $event->categoryOverride,
        ]);
    }
}
