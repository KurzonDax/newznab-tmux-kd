<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ObfuscationRecovery\RecoveryNameEvidence;
use App\Services\ObfuscationRecovery\RecoveryNameScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NameWriteTables;
use Tests\TestCase;

use function Termwind\renderUsing;

/**
 * Records what each rename call site writes today (issue #1120, Section 1): one row per
 * literal (type, method) pair the code passes to ReleaseUpdateService::updateRelease().
 */
class NameWriteCharacterizationTest extends TestCase
{
    use IsolatedSqliteDatabase;
    use NameWriteTables;

    private const string PLAUSIBLE = 'Some.Movie.2024.1080p.BluRay.x264-GROUP';

    /** Not a plausible release title: only a source that skips plausibility accepts it. */
    private const string IMPLAUSIBLE = 'Some Movie Night';

    private const string SRRDB_IMDB = '7654321';

    /**
     * Call site => [type, method, PreDB ids the site can pass, descriptive-title candidate].
     *
     * @var array<string, array{0: string, 1: string, 2: list<int>, 3: bool}>
     */
    private const array CALL_SITES = [
        'NFO extractor' => ['NFO, ', 'nfoCheck: Title (Year)', [0], false],
        'NFO PreDB match' => ['NFO, ', 'preDB: Match', [77], false],
        'NFO pattern checker' => ['NFO, ', 'TV: Generic TV', [0], false],
        'SRRDB archive CRC' => ['SRRDB, ', 'srrdb: archive-crc', [0, 77], false],
        'UID election' => ['UID, ', 'uidCheck: Unique_ID group election', [0, 77], false],
        'SRR extension' => ['SRR, ', 'fileCheck: SRR extension', [0], false],
        'PreDB filename match' => ['Filenames, ', 'PreDb: Filename match', [0, 77], false],
        'CRC32 donor' => ['CRC32, ', 'crcCheck: CRC32', [0, 77], false],
        'PAR2 hash donor' => ['PAR2 hash, ', 'hashCheck: PAR2 hash_16K', [0, 77], false],
        'file name' => ['Filenames, ', 'fileCheck: Scene release with group', [0], false],
        'file name NZBSPLIT wrapper' => ['Filenames, ', 'fileCheck: NZBSPLIT wrapper', [0], false],
        'PreDB full text' => ['PreDB FT Exact, ', 'Title Match source: srrdb', [77], false],
        'XXX SDPORN' => ['XXX filenames, ', 'fileCheck: XXX SDPORN', [0], false],
        'MediaInfo movie name' => ['Mediainfo, ', 'MediaInfo: Movie Name', [0, 77], false],
        'PAR2 PreDB match' => ['PAR2, ', 'preDB: Match', [77], false],
        'PAR2 file name' => ['PAR2, ', 'fileCheck: Scene release with group', [0], false],
        'PAR2 NZBSPLIT wrapper' => ['PAR2, ', 'fileCheck: NZBSPLIT wrapper', [0], false],
        'PreDB file match' => ['PreDB file match, ', 'file matched source: srrdb', [0], false],
        'NZB contents NZBSPLIT wrapper' => ['Filenames, ', 'NZBSPLIT wrapper', [0], false],
        'RarInfo file name' => ['Filenames, ', 'RarInfo FileName Match', [0, 77], false],
        'RarInfo PreDB' => ['Filenames, ', 'RarInfo PreDB Match', [77], false],
        'original subject repair' => ['Filenames, ', 'Original subject repair', [0], false],
        'original subject repair with PreDB' => ['Filenames, ', 'preDB: Restored original subject', [77], false],
        'size mismatch' => ['', 'size-mismatch / season pack', [0], false],
        'Release Inform API' => ['Filenames, ', 'Release Inform API', [0], false],
        'file descriptive title' => ['Filenames, ', 'fileCheck: Descriptive title', [0], true],
        'RarInfo descriptive title' => ['Filenames, ', 'RarInfo: Descriptive title', [0], true],
        'MediaInfo descriptive title' => ['Mediainfo, ', ReleaseUpdateService::DESCRIPTIVE_MEDIA_TITLE_METHOD, [0], true],
    ];

    /**
     * Call site => [skips plausibility, trusted donor, stored name_source, proc_* set on acceptance,
     * writes isrenamed/iscategorized] for a candidate without a PreDB id.
     *
     * @var array<string, array{0: bool, 1: bool, 2: ?string, 3: list<string>, 4: bool}>
     */
    private const array EXPECTED = [
        'NFO extractor' => [false, true, 'NFO', ['proc_nfo'], true],
        'NFO PreDB match' => [true, true, 'NFO', ['proc_nfo'], true],
        'NFO pattern checker' => [false, true, 'NFO', ['proc_nfo'], true],
        'SRRDB archive CRC' => [true, true, 'SRRDB', ['proc_srrdb'], true],
        'UID election' => [true, true, 'UID', ['proc_uid'], true],
        'SRR extension' => [true, true, 'SRR', ['proc_srr'], true],
        'PreDB filename match' => [true, true, 'Filenames', ['proc_files'], true],
        'CRC32 donor' => [true, true, 'CRC32', ['proc_crc32'], true],
        'PAR2 hash donor' => [true, true, 'PAR2 hash', ['proc_hash16k'], true],
        'file name' => [false, false, 'Filenames', ['proc_files'], true],
        'file name NZBSPLIT wrapper' => [false, true, 'Filenames', ['proc_files'], true],
        'PreDB full text' => [true, true, 'PreDB FT Exact', [], true],
        'XXX SDPORN' => [false, false, 'XXX filenames', ['proc_xxx'], true],
        'MediaInfo movie name' => [false, false, 'Mediainfo', ['proc_media_movie'], true],
        'PAR2 PreDB match' => [true, true, 'PAR2', ['proc_par2'], true],
        'PAR2 file name' => [false, true, 'PAR2', ['proc_par2'], true],
        'PAR2 NZBSPLIT wrapper' => [false, true, 'PAR2', ['proc_par2'], true],
        'PreDB file match' => [true, true, 'PreDB file match', [], false],
        'NZB contents NZBSPLIT wrapper' => [false, true, 'Filenames', ['proc_files'], true],
        'RarInfo file name' => [false, true, 'Filenames', ['proc_files'], true],
        'RarInfo PreDB' => [true, true, 'Filenames', ['proc_files'], true],
        'original subject repair' => [false, false, 'Filenames', ['proc_files'], true],
        'original subject repair with PreDB' => [true, true, 'Filenames', ['proc_files'], true],
        'size mismatch' => [false, false, null, [], false],
        'Release Inform API' => [false, false, 'Filenames', ['proc_files'], true],
        'file descriptive title' => [false, false, 'Filenames', ['proc_files'], true],
        'RarInfo descriptive title' => [false, false, 'Filenames', ['proc_files'], true],
        'MediaInfo descriptive title' => [false, false, 'Mediainfo', ['proc_media_movie'], true],
    ];

    /** The name source each call site passes. */
    private const array SOURCES = [
        'NFO extractor' => NameSource::NfoExtract,
        'NFO PreDB match' => NameSource::NfoPreDbMatch,
        'NFO pattern checker' => NameSource::NfoPattern,
        'SRRDB archive CRC' => NameSource::SrrdbArchiveCrc,
        'UID election' => NameSource::UidElection,
        'SRR extension' => NameSource::SrrFile,
        'PreDB filename match' => NameSource::FilePreDbFilenameMatch,
        'CRC32 donor' => NameSource::Crc32Donor,
        'PAR2 hash donor' => NameSource::Par2HashDonor,
        'file name' => NameSource::FileName,
        'file name NZBSPLIT wrapper' => NameSource::FileNzbSplitWrapper,
        'PreDB full text' => NameSource::PreDbFullText,
        'XXX SDPORN' => NameSource::XxxFile,
        'MediaInfo movie name' => NameSource::MediaMovieName,
        'PAR2 PreDB match' => NameSource::Par2PreDbMatch,
        'PAR2 file name' => NameSource::Par2File,
        'PAR2 NZBSPLIT wrapper' => NameSource::Par2NzbSplitWrapper,
        'PreDB file match' => NameSource::PreDbFileMatch,
        'NZB contents NZBSPLIT wrapper' => NameSource::FileNzbSplitWrapper,
        'RarInfo file name' => NameSource::FileRarInfoName,
        'RarInfo PreDB' => NameSource::FileRarInfoPreDb,
        'original subject repair' => NameSource::FileOriginalSubject,
        'original subject repair with PreDB' => NameSource::FileOriginalSubjectPreDb,
        'size mismatch' => NameSource::SizeMismatch,
        'Release Inform API' => NameSource::FileReleaseInform,
        'file descriptive title' => NameSource::FileDescriptiveTitle,
        'RarInfo descriptive title' => NameSource::FileDescriptiveTitle,
        'MediaInfo descriptive title' => NameSource::MediaDescriptiveTitle,
    ];

    /** Descriptive-title call sites rename from these candidates; the others from PLAUSIBLE. */
    private const array DESCRIPTIVE_CANDIDATES = [
        'file descriptive title' => ['Example Studio - Evening Feature (2026-01-11).mp4', 'Example Studio - Evening Feature (2026-01-11)'],
        'RarInfo descriptive title' => ['Example Studio - Evening Feature (2026-01-11).mp4', 'Example Studio - Evening Feature (2026-01-11)'],
        'MediaInfo descriptive title' => ['Example Studio - Extended Evening Feature (2026-01-11)', 'Example Studio - Extended Evening Feature (2026-01-11)'],
    ];

    private const array PROC_COLUMNS = [
        'proc_pp', 'proc_par2', 'proc_nfo', 'proc_files', 'proc_xxx', 'proc_uid', 'proc_media_movie', 'proc_srr',
        'proc_hash16k', 'proc_crc32', 'proc_srrdb',
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
        $this->updates = $this->updateService();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, int}> */
    public static function callSites(): iterable
    {
        foreach (self::CALL_SITES as $site => [, , $preIds]) {
            foreach ($preIds as $preId) {
                yield $site.($preId > 0 ? ' with a PreDB id' : '') => [$site, $preId];
            }
        }
    }

    #[DataProvider('callSites')]
    public function test_an_accepted_rename_writes_the_call_sites_name_trust_status_and_links(string $site, int $preId): void
    {
        [, $trustedDonor, $nameSource, $procColumns, $writesStatus] = self::EXPECTED[$site];
        [$candidate, $title] = self::DESCRIPTIVE_CANDIDATES[$site] ?? [self::PLAUSIBLE, self::PLAUSIBLE];
        $id = $this->linkedRelease(1);

        $this->assertTrue($this->rename($site, $this->snapshot($id), $candidate, $preId), $site);

        $row = $this->releaseRow($id);
        $this->assertSame($title, $row['searchname']);
        $this->assertSame(Release::searchNameValues($title), array_intersect_key($row, Release::searchNameValues($title)));
        $this->assertSame($trustedDonor || $preId > 0 ? 1 : 0, (int) $row['is_trusted_name']);
        $this->assertSame($nameSource, $row['name_source']);
        $this->assertSame($writesStatus ? 1 : 0, (int) $row['isrenamed']);
        $this->assertSame($writesStatus ? 1 : 0, (int) $row['iscategorized']);
        foreach (self::PROC_COLUMNS as $column) {
            $this->assertSame(in_array($column, $procColumns, true) ? 1 : 0, (int) $row[$column], $column);
        }
        $this->assertSame($preId, (int) $row['predb_id']);
        $this->assertSame([
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'movieinfo_id' => null,
            'imdbid' => $site === 'SRRDB archive CRC' ? (int) self::SRRDB_IMDB : null,
            'musicinfo_id' => null,
            'consoleinfo_id' => null,
            'bookinfo_id' => null,
            'anidbid' => null,
            'gamesinfo_id' => 0,
        ], $this->linksOf($id));
        $this->assertSame(Category::OTHER_HASHED, (int) $row['categories_id']);
        $this->assertSame([$id], $this->synchronized);
        $this->assertRenameEvent($id, 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $title, null);
    }

    #[DataProvider('callSites')]
    public function test_the_cli_echo_line_names_the_type_and_method(string $site, int $preId): void
    {
        config(['nntmux.echocli' => true]);
        $this->updates = $this->updateService();
        [$type, $method] = self::CALL_SITES[$site];
        [$candidate] = self::DESCRIPTIVE_CANDIDATES[$site] ?? [self::PLAUSIBLE];
        $id = $this->linkedRelease(1);

        $rendered = new BufferedOutput;
        renderUsing($rendered);
        ob_start();
        try {
            $this->rename($site, $this->snapshot($id), $candidate, $preId, show: true);
        } finally {
            $echoed = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) ob_get_clean());
            renderUsing(null);
        }

        $this->assertStringContainsString('Method:     ', $echoed);
        $this->assertContains($type.$method, array_map('trim', explode("\n", $rendered->fetch())));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function missTypes(): iterable
    {
        $columns = [
            'NFO, ' => 'proc_nfo', 'Filenames, ' => 'proc_files', 'XXX filenames, ' => 'proc_xxx', 'PAR2, ' => 'proc_par2',
            'PAR2 hash, ' => 'proc_hash16k', 'SRR, ' => 'proc_srr', 'UID, ' => 'proc_uid', 'Mediainfo, ' => 'proc_media_movie',
            'CRC32, ' => 'proc_crc32', 'SRRDB, ' => null, 'PreDB FT Exact, ' => null, 'PreDB file match, ' => null, '' => null,
        ];
        foreach ($columns as $type => $column) {
            yield ($type === '' ? 'empty type' : rtrim($type, ', ')) => [$type, $column];
        }
    }

    #[DataProvider('missTypes')]
    public function test_a_miss_sets_the_types_processing_column(string $type, ?string $column): void
    {
        $id = $this->linkedRelease(1);

        (new ReflectionMethod(NameFixingService::class, 'updateProcessingFlags'))
            ->invoke(new NameFixingService($this->updates), $type, $id);

        $row = $this->releaseRow($id);
        foreach (self::PROC_COLUMNS as $procColumn) {
            $this->assertSame($procColumn === $column ? 1 : 0, (int) $row[$procColumn], $procColumn);
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function plainCallSites(): iterable
    {
        foreach (self::callSites() as $name => [$site, $preId]) {
            if (! isset(self::DESCRIPTIVE_CANDIDATES[$site])) {
                yield $name => [$site, $preId];
            }
        }
    }

    #[DataProvider('plainCallSites')]
    public function test_an_implausible_title_is_accepted_only_by_a_source_that_skips_plausibility(string $site, int $preId): void
    {
        $id = $this->linkedRelease(1);

        $accepted = $this->rename($site, $this->snapshot($id), self::IMPLAUSIBLE, $preId);

        $this->assertSame($this->skipsPlausibility($site, $preId), $accepted);
        $this->assertSame($accepted ? 1 : 0, Event::dispatched(ReleaseNameFixed::class)->count());
    }

    public function test_a_rename_without_status_marks_the_release_categorized_only(): void
    {
        $id = $this->linkedRelease(1);

        $this->assertTrue($this->renameWith(true, 'file name', $this->snapshot($id), self::PLAUSIBLE, 0, nameStatus: false));

        $row = $this->releaseRow($id);
        $this->assertSame(self::PLAUSIBLE, $row['searchname']);
        $this->assertSame('Filenames', $row['name_source']);
        $this->assertSame(0, (int) $row['isrenamed']);
        $this->assertSame(1, (int) $row['iscategorized']);
        $this->assertSame(0, (int) $row['proc_files']);
        $this->assertSame(0, (int) $row['videos_id']);
        $this->assertNull($row['movieinfo_id']);
    }

    public function test_a_dry_run_reports_the_rename_without_writing_it(): void
    {
        $id = $this->linkedRelease(1);

        $this->assertTrue($this->renameWith(false, 'file name', $this->snapshot($id), self::PLAUSIBLE, 0));

        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $this->releaseRow($id)['searchname']);
        $this->assertSame(self::LINKED['movieinfo_id'], $this->linksOf($id)['movieinfo_id']);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        $this->assertSame([], $this->synchronized);
    }

    public function test_a_candidate_matching_the_current_name_is_not_a_rename(): void
    {
        $id = $this->linkedRelease(1, ['searchname' => self::PLAUSIBLE, 'categories_id' => Category::MOVIE_HD]);

        $this->assertFalse($this->rename('file name', $this->snapshot($id), strtolower(self::PLAUSIBLE), 0));

        $this->assertSame(self::LINKED['videos_id'], $this->linksOf($id)['videos_id']);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_a_promotional_title_is_refused_even_with_a_predb_id(): void
    {
        $id = $this->linkedRelease(1);

        $this->assertFalse($this->rename('PAR2 PreDB match', $this->snapshot($id), 'Downloaded from example.org', 77));

        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_an_episode_title_does_not_rename_a_release_holding_other_episodes(): void
    {
        $id = $this->linkedRelease(1, ['size' => 1000]);
        DB::table('release_files')->insert([
            ['releases_id' => $id, 'name' => 'Show.S01E01.1080p.WEB-DL-GRP.mkv', 'size' => 400],
            ['releases_id' => $id, 'name' => 'Show.S01E02.1080p.WEB-DL-GRP.mkv', 'size' => 400],
        ]);

        $this->assertFalse($this->rename('PAR2 hash donor', $this->snapshot($id), 'Show.S01E01.1080p.WEB-DL-GRP', 0));
        $this->assertTrue($this->rename('PAR2 hash donor', $this->snapshot($id), 'Show.S01.1080p.WEB-DL-GRP', 0));
    }

    public function test_recovery_evidence_without_a_publication_refuses_the_rename(): void
    {
        $id = $this->linkedRelease(1);
        $evidence = new RecoveryNameEvidence(1, str_repeat('a', 64), RecoveryNameScope::SingleFile, ['file-1']);

        $this->assertFalse($this->renameWith(true, 'PAR2 file name', $this->snapshot($id), self::PLAUSIBLE, 0, evidence: $evidence));

        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $this->releaseRow($id)['searchname']);
    }

    public function test_one_service_renames_a_release_once(): void
    {
        $first = $this->linkedRelease(1);
        $second = $this->linkedRelease(2);

        $this->assertTrue($this->rename('file name', $this->snapshot($first), self::PLAUSIBLE, 0));
        $this->assertFalse($this->rename('PAR2 PreDB match', $this->snapshot($first), 'Other.Movie.2024.1080p.BluRay.x264-GROUP', 77));
        $this->assertTrue($this->rename('file name', $this->snapshot($second), self::PLAUSIBLE, 0));

        $this->assertSame(self::PLAUSIBLE, $this->releaseRow($first)['searchname']);
        $this->assertSame(0, (int) $this->releaseRow($first)['predb_id']);
        $this->assertSame(2, $this->updates->fixed);
    }

    /**
     * checkName() reports only its own call: a later release's PAR2 file-name check no longer
     * reports an earlier release's rename, and its miss sets proc_par2 (decision on #1120).
     */
    public function test_check_name_reports_only_its_own_call(): void
    {
        $renamed = $this->linkedRelease(1);
        $unnamed = $this->linkedRelease(2);
        $naming = new NameFixingService($this->updates);

        $this->assertTrue($naming->checkName((object) [...$this->releaseRow($renamed), 'releases_id' => $renamed,
            'textstring' => self::PLAUSIBLE.'.mkv'], true, 'PAR2, ', true, false));
        $this->assertFalse($naming->checkName((object) [...$this->releaseRow($unnamed), 'releases_id' => $unnamed,
            'textstring' => 'zzzz'], true, 'PAR2, ', true, false));

        $this->assertSame(1, (int) $this->releaseRow($unnamed)['proc_par2']);
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $this->releaseRow($unnamed)['searchname']);
    }

    private function updateService(): ReleaseUpdateService
    {
        return new ReleaseUpdateService(searchSyncCoordinator: new ReleaseSearchSyncCoordinator(
            new PersistenceMetricsCollector,
            function (int $releaseId): void {
                $this->synchronized[] = $releaseId;
            },
        ));
    }

    /** The release as a call site hands it to the update service. */
    private function snapshot(int $id): object
    {
        return (object) [...$this->releaseRow($id), 'releases_id' => $id, 'relsize' => $this->releaseRow($id)['size']];
    }

    private function rename(string $site, object $release, string $candidate, int $preId, bool $show = false): bool
    {
        return $this->renameWith(true, $site, $release, $candidate, $preId, $show);
    }

    /** Whether the call site's rename was accepted, as the update service reports it. */
    private function renameWith(
        bool $write,
        string $site,
        object $release,
        string $candidate,
        int $preId,
        bool $show = false,
        bool $nameStatus = true,
        ?RecoveryNameEvidence $evidence = null,
    ): bool {
        [$type, $method, , $descriptive] = self::CALL_SITES[$site];
        if ($type === 'SRRDB, ') {
            $release->srrdb_imdbid = self::SRRDB_IMDB;
        }

        return $this->updates->updateRelease($release, $candidate, $method, $write, self::SOURCES[$site], $nameStatus, $show, $preId, $descriptive, $evidence)->renamed;
    }

    /** Whether the call site accepts an implausible title, judged on a fresh release and service. */
    private function skipsPlausibility(string $site, int $preId): bool
    {
        [$skips] = self::EXPECTED[$site];

        return $skips || $preId > 0;
    }

    private function assertRenameEvent(int $id, string $oldName, string $newName, ?int $override): void
    {
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        $event = Event::dispatched(ReleaseNameFixed::class)->first()[0];
        $this->assertSame([$id, $oldName, $newName, Category::OTHER_HASHED, 1, 'poster@example.test', $override], [
            $event->releaseId, $event->oldName, $event->newName, $event->oldCategoryId, (int) $event->groupId, $event->poster, $event->categoryOverride,
        ]);
    }
}
