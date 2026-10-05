<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BlacklistConstants;
use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Models\Category;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseDuplicateAbsorber;
use App\Support\Data\DuplicateAbsorbResult;
use App\Support\ReleaseNameNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\PhantomTrailingSets;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class NzbImportServiceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
            'nzbsplitlevel' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        Cache::flush();

        Schema::create('categories', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->integer('status');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_begin_import_uses_specific_messages_and_counts_duplicates_separately(): void
    {
        $duplicateFile = $this->makeNzbFile('duplicate');
        $blacklistedFile = $this->makeNzbFile('blacklisted');
        $noGroupFile = $this->makeNzbFile('nogroup');
        $failedFile = $this->makeNzbFile('failed');

        $service = new class(['Browser' => true], [NzbImportStatus::Duplicate, NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup, NzbImportStatus::Failed]) extends NzbImportService
        {
            /**
             * @param  array<NzbImportStatus>  $statuses
             */
            public function __construct(array $options, private array $statuses)
            {
                parent::__construct($options);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $status = array_shift($this->statuses) ?? NzbImportStatus::Failed;

                match ($status) {
                    NzbImportStatus::Duplicate => $this->echoOut('This release is already in our DB so skipping: duplicate subject'),
                    NzbImportStatus::Blacklisted => $this->echoOut('Subject is blacklisted: blacklisted subject'),
                    NzbImportStatus::NoGroup => $this->echoOut('No group found for missing-group subject (one of alt.test are missing'),
                    default => null,
                };

                return $status;
            }
        };

        $result = $service->beginImport(
            [$duplicateFile, $blacklistedFile, $noGroupFile, $failedFile],
            delete: false,
            deleteFailed: true,
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('This release is already in our DB so skipping: duplicate subject', $result);
        $this->assertStringContainsString('Subject is blacklisted: blacklisted subject', $result);
        $this->assertStringContainsString('No group found for missing-group subject (one of alt.test are missing', $result);
        $this->assertSame(1, substr_count($result, 'ERROR: Failed to insert NZB!'));
        $this->assertStringContainsString('Processed 0 NZBs in ', $result);
        $this->assertStringContainsString('3 NZBs were skipped, 1 were duplicates.', $result);

        $this->assertFileDoesNotExist($duplicateFile);
        $this->assertFileDoesNotExist($blacklistedFile);
        $this->assertFileDoesNotExist($noGroupFile);
        $this->assertFileDoesNotExist($failedFile);
    }

    public function test_begin_import_deletes_duplicate_blacklisted_and_no_group_files_when_delete_is_enabled(): void
    {
        $duplicateFile = $this->makeNzbFile('duplicate-delete');
        $blacklistedFile = $this->makeNzbFile('blacklisted-delete');
        $noGroupFile = $this->makeNzbFile('nogroup-delete');

        $service = new class(['Browser' => true], [NzbImportStatus::Duplicate, NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup]) extends NzbImportService
        {
            /**
             * @param  array<NzbImportStatus>  $statuses
             */
            public function __construct(array $options, private array $statuses)
            {
                parent::__construct($options);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $status = array_shift($this->statuses) ?? NzbImportStatus::Failed;

                match ($status) {
                    NzbImportStatus::Duplicate => $this->echoOut('This release is already in our DB so skipping: duplicate subject'),
                    NzbImportStatus::Blacklisted => $this->echoOut('Subject is blacklisted: blacklisted subject'),
                    NzbImportStatus::NoGroup => $this->echoOut('No group found for missing-group subject (one of alt.test are missing'),
                    default => null,
                };

                return $status;
            }
        };

        $result = $service->beginImport(
            [$duplicateFile, $blacklistedFile, $noGroupFile],
            delete: true,
            deleteFailed: false,
        );

        $this->assertIsString($result);
        $this->assertStringNotContainsString('ERROR: Failed to insert NZB!', $result);
        $this->assertStringContainsString('2 NZBs were skipped, 1 were duplicates.', $result);

        $this->assertFileDoesNotExist($duplicateFile);
        $this->assertFileDoesNotExist($blacklistedFile);
        $this->assertFileDoesNotExist($noGroupFile);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nzbFilenameProvider(): array
    {
        return [
            'plain .nzb' => ['foo.nzb', 'foo'],
            'plain .nzb.gz' => ['foo.nzb.gz', 'foo'],
            'mkv wrapper' => ['foo.mkv.nzb.gz', 'foo'],
            'uppercase wrapper' => ['bar.MP4.NZB.GZ', 'bar'],
            'release with brackets' => [
                '[DKB] Kami-tachi ni Hirowareta Otoko - S01E07 [1080p][H.265 10bit].mkv.nzb.gz',
                '[DKB] Kami-tachi ni Hirowareta Otoko - S01E07 [1080p][H.265 10bit]',
            ],
            'non-media inner ext stays' => ['release.name.nzb.gz', 'release.name'],
            'no trailing media ext' => ['something.nzb', 'something'],
            'full path input' => [sys_get_temp_dir().'/nested/path/Show - 01.mp4.nzb.gz', 'Show - 01'],
        ];
    }

    /**
     * @dataProvider nzbFilenameProvider
     */
    #[DataProvider('nzbFilenameProvider')]
    public function test_derive_release_name_strips_wrapper_and_media_extension(string $input, string $expected): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function deriveForTest(string $path): string
            {
                return $this->deriveReleaseNameFromNzbPath($path);
            }
        };

        $this->assertSame($expected, $service->deriveForTest($input));
    }

    public function test_group_names_are_trimmed_before_import(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function groupNameForTest(\SimpleXMLElement $group): string
            {
                return $this->normalizeGroupName($group);
            }
        };

        $group = simplexml_load_string('<group> alt.binaries.xylo </group>');
        $this->assertInstanceOf(\SimpleXMLElement::class, $group);

        $this->assertSame('alt.binaries.xylo', $service->groupNameForTest($group));
    }

    public function test_nzb_category_metadata_resolves_active_id_and_unique_case_insensitive_title(): void
    {
        $this->insertCategory(2040, 'HD', Category::STATUS_ACTIVE);
        $this->insertCategory(3040, 'Lossless', Category::STATUS_ACTIVE);

        $this->assertSame(2040, $this->resolveNzbCategory('<meta type="category"> 2040 </meta>'));
        $this->assertSame(3040, $this->resolveNzbCategory('<meta type="CATEGORY"> lossLESS </meta>'));
        $this->assertSame(2040, $this->resolveNzbCategory(
            '<meta type="category">2040</meta>',
            ' xmlns="http://www.newzbin.com/DTD/2003/nzb"'
        ));
    }

    public function test_nzb_category_metadata_rejects_unknown_inactive_disabled_and_ambiguous_values(): void
    {
        $this->insertCategory(2040, 'HD', Category::STATUS_ACTIVE);
        $this->insertCategory(3040, 'Lossless', Category::STATUS_INACTIVE);
        $this->insertCategory(5040, 'HD', Category::STATUS_ACTIVE);
        $this->insertCategory(6040, 'X264', Category::STATUS_DISABLED);

        $this->assertNull($this->resolveNzbCategory('<meta type="category">9999</meta>'));
        $this->assertNull($this->resolveNzbCategory('<meta type="category">3040</meta>'));
        $this->assertNull($this->resolveNzbCategory('<meta type="category">X264</meta>'));
        $this->assertNull($this->resolveNzbCategory('<meta type="category">HD</meta>'));
    }

    public function test_nzb_category_metadata_rejects_conflicting_matches(): void
    {
        $this->insertCategory(2040, 'Movie HD', Category::STATUS_ACTIVE);
        $this->insertCategory(5040, 'TV HD', Category::STATUS_ACTIVE);

        $this->assertNull($this->resolveNzbCategory(
            '<meta type="category">2040</meta><meta type="category">TV HD</meta>'
        ));
    }

    public function test_nzb_category_metadata_falls_back_when_category_is_missing_or_blank(): void
    {
        $this->assertNull($this->resolveNzbXml('<nzb><file subject="example" /></nzb>'));
        $this->assertNull($this->resolveNzbCategory(''));
        $this->assertNull($this->resolveNzbCategory('<meta type="category"> </meta>'));
        $this->assertNull($this->resolveNzbCategory('<meta type="password">secret</meta>'));
    }

    public function test_compressed_nzb_write_returns_false_for_an_unwritable_destination(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function writeForTest(string $path, string $contents): bool
            {
                return $this->writeCompressedNzb($path, $contents);
            }
        };

        $path = sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(5)).'/release.nzb.gz';

        $this->assertFalse($service->writeForTest($path, '<nzb />'));
        $this->assertFileDoesNotExist($path);
    }

    public function test_failed_compressed_storage_removes_the_inserted_release_and_all_artifacts(): void
    {
        $nzbRoot = $this->makeTempDirectory('failed-import-nzb').'/';
        $coversRoot = $this->makeTempDirectory('failed-import-covers');
        config([
            'nntmux_settings.path_to_nzbs' => $nzbRoot,
            'nntmux_settings.covers_path' => $coversRoot,
        ]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40)->unique();
        });

        Search::shouldReceive('updateRelease')->once()->with(7);
        Search::shouldReceive('deleteReleases')->once()->with([7]);

        $service = new class(['Browser' => true]) extends NzbImportService
        {
            /** @var list<string> */
            public array $artifactPaths = [];

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $this->relGuid = str_repeat('f', 40);
                $this->relId = 7;
                DB::table('releases')->insert(['id' => $this->relId, 'guid' => $this->relGuid]);
                Search::updateRelease($this->relId);

                return NzbImportStatus::Inserted;
            }

            protected function writeCompressedNzb(string $path, string $contents): bool
            {
                $images = new ReleaseImageService;
                $this->artifactPaths = [
                    $path,
                    $images->vidSavePath.$this->relGuid.'.ogv',
                    $images->audSavePath.$this->relGuid.'.mp3',
                    $images->audSavePath.$this->relGuid.'_spectrum.png',
                ];

                foreach ($this->artifactPaths as $artifactPath) {
                    File::ensureDirectoryExists(dirname($artifactPath));
                    File::put($artifactPath, 'partial import');
                }

                return false;
            }
        };
        $source = $this->makeNzbFile('failed-compressed-storage');

        $result = $service->beginImport([$source], deleteFailed: true);

        $this->assertIsString($result);
        $this->assertDatabaseMissing('releases', ['id' => 7]);
        $this->assertFileDoesNotExist($source);
        foreach ($service->artifactPaths as $artifactPath) {
            $this->assertFileDoesNotExist($artifactPath);
        }
    }

    public function test_an_imported_phantom_trailing_file_is_measured_and_stored_against_the_files_held(): void
    {
        $details = $this->scannedDetails(PhantomTrailingSets::base());

        $this->assertSame(100.0, $details['completion']);
        $this->assertSame(PhantomTrailingSets::HELD, $details['declaredFiles']);
    }

    public function test_an_imported_set_that_is_not_a_phantom_trailing_file_keeps_its_declared_count(): void
    {
        $details = $this->scannedDetails(PhantomTrailingSets::lastVolumeNotARemainder());

        $this->assertEqualsWithDelta(12 / 13 * 100, $details['completion'], 0.0001);
        $this->assertSame(PhantomTrailingSets::DECLARED, $details['declaredFiles']);
    }

    public function test_an_explicit_release_name_overrides_the_filename_derivation(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            /** @var list<string> */
            public array $names = [];

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $this->names[] = $nzbFileName;

                return NzbImportStatus::Failed;
            }
        };

        $name = 'Show: The "Title" / Part 1?';
        $service->beginImport([$this->makeNzbFile('derived-name.mkv')], useNzbName: true, releaseName: $name);

        $this->assertSame([$name], $service->names);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function refusingRuleProvider(): array
    {
        return [
            'blacklist rule matching the subject' => [BlacklistConstants::OPTYPE_BLACKLIST, 'Hand\.Picked'],
            'group whitelist that does not match' => [BlacklistConstants::OPTYPE_WHITELIST, '^never-matches$'],
        ];
    }

    #[DataProvider('refusingRuleProvider')]
    public function test_skip_blacklist_bypasses_the_blacklist_and_whitelist_check(int $opType, string $regex): void
    {
        $this->createBlacklistTables();
        DB::table('binaryblacklist')->insert([
            'groupname' => 'alt.test',
            'regex' => $regex,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_SUBJECT,
            'optype' => $opType,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
        ]);
        $file = $this->makeImportableNzbFile('Hand.Picked.Release yEnc (1/1)');

        $skipped = $this->recordingImporter(new BlacklistService);
        $skipped->beginImport([$file], resultCallback: $this->collectResults($skippedResults), skipBlacklist: true);
        $this->assertNull(DB::table('binaryblacklist')->value('last_activity'), 'A skipped check records no usage.');

        $refused = $this->recordingImporter(new BlacklistService);
        $refused->beginImport([$file], resultCallback: $this->collectResults($refusedResults));

        $this->assertSame(NzbImportStatus::Failed, $skippedResults[0]['status']);
        $this->assertCount(1, $skipped->inserted);
        $this->assertSame(NzbImportStatus::Blacklisted, $refusedResults[0]['status']);
        $this->assertSame([], $refused->inserted);
    }

    public function test_skip_blacklist_never_consults_the_blacklist_service(): void
    {
        $blacklist = new class extends BlacklistService
        {
            public int $calls = 0;

            public function isBlackListed(array $msg, string $groupName): bool
            {
                $this->calls++;

                return true;
            }
        };
        $service = $this->recordingImporter($blacklist);

        $service->beginImport([$this->makeImportableNzbFile('Any.Release yEnc (1/1)')], skipBlacklist: true);

        $this->assertSame(0, $blacklist->calls);
        $this->assertCount(1, $service->inserted);
    }

    /**
     * @return array<string, array{0: DuplicateAbsorbResult|null, 1: bool, 2: string|null}>
     */
    public static function absorbOutcomeProvider(): array
    {
        return [
            'absorbed' => [DuplicateAbsorbResult::absorbed(), true, 'absorbed'],
            'not better' => [DuplicateAbsorbResult::notBetter(), false, 'not_better'],
            'deferred' => [DuplicateAbsorbResult::deferred(), false, 'deferred'],
            'failed' => [DuplicateAbsorbResult::failed('No stored NZB'), false, 'failed'],
            'unsupported reason' => [null, false, null],
        ];
    }

    #[DataProvider('absorbOutcomeProvider')]
    public function test_the_callback_reports_the_matched_duplicate_and_its_absorb_outcome(
        ?DuplicateAbsorbResult $absorb,
        bool $absorbed,
        ?string $outcome,
    ): void {
        $this->createDuplicateTables();
        $this->bindAbsorber($absorb);

        $service = $this->duplicateImporter();
        $service->beginImport(
            [$this->makeImportableNzbFile('Existing.Release yEnc (1/1)')],
            resultCallback: $this->collectResults($results),
            releaseName: 'Existing.Release',
        );

        $this->assertCount(1, $results);
        $this->assertSame(NzbImportStatus::Duplicate, $results[0]['status']);
        $this->assertSame(41, $results[0]['release_id']);
        $this->assertSame(str_repeat('e', 40), $results[0]['release_guid']);
        $this->assertSame($absorbed, $results[0]['absorbed']);
        $this->assertSame($outcome, $results[0]['absorb_outcome']);
        $this->assertNull($results[0]['error']);
    }

    public function test_the_callback_carries_the_error_for_blacklisted_no_group_and_failed_imports(): void
    {
        $blacklist = new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return true;
            }
        };
        $blacklisted = $this->makeImportableNzbFile('Blocked.Release yEnc (1/1)');
        $noGroup = $this->makeImportableNzbFile('Lost.Release yEnc (1/1)', 'not a group');
        $unparsable = $this->makeNzbFile('unparsable');
        file_put_contents($unparsable, 'not xml at all');

        $service = $this->recordingImporter($blacklist);
        $service->beginImport([$blacklisted, $noGroup, $unparsable], resultCallback: $this->collectResults($results));

        $this->assertSame(
            [
                [NzbImportStatus::Blacklisted, 'Subject is blacklisted: Blocked.Release yEnc (1/1)'],
                // An invalid group name is listed as empty, as the importer always printed it.
                [NzbImportStatus::NoGroup, 'No group found for Lost.Release yEnc (1/1) (one of  are missing'],
                [NzbImportStatus::Failed, 'ERROR: Unable to load NZB XML data: '.$unparsable],
            ],
            array_map(static fn (array $result): array => [$result['status'], $result['error']], $results),
        );
        foreach ($results as $result) {
            $this->assertNull($result['release_id']);
            $this->assertNull($result['release_guid']);
            $this->assertFalse($result['absorbed']);
            $this->assertNull($result['absorb_outcome']);
        }
    }

    public function test_an_exception_while_inserting_is_reported_as_the_error(): void
    {
        config(['nntmux.echocli' => false]);
        $service = new class extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                throw new \RuntimeException('database went away');
            }
        };
        $file = $this->makeNzbFile('throws');

        $service->beginImport([$file], resultCallback: $this->collectResults($results));

        $this->assertSame(NzbImportStatus::Failed, $results[0]['status']);
        $this->assertSame('ERROR: Problem inserting: '.$file.': database went away', $results[0]['error']);
    }

    public function test_keep_release_marks_an_inserted_release(): void
    {
        $this->createKeptReleaseTables();

        $this->insertingImporter()->beginImport([$this->makeNzbFile('kept-insert')], keepRelease: true);

        $this->assertSame([7], DB::table('kept_releases')->pluck('releases_id')->map(intval(...))->all());
    }

    public function test_keep_release_marks_the_matched_release_of_a_duplicate(): void
    {
        $this->createDuplicateTables();
        $this->createKeptReleaseTables(withReleases: false);
        $this->bindAbsorber(DuplicateAbsorbResult::deferred());

        $this->duplicateImporter()->beginImport(
            [$this->makeImportableNzbFile('Existing.Release yEnc (1/1)')],
            releaseName: 'Existing.Release',
            keepRelease: true,
        );

        $this->assertSame([41], DB::table('kept_releases')->pluck('releases_id')->map(intval(...))->all());
    }

    public function test_without_keep_release_nothing_is_marked(): void
    {
        $this->createDuplicateTables();
        $this->createKeptReleaseTables(withReleases: false);
        $this->bindAbsorber(DuplicateAbsorbResult::notBetter());

        $this->insertingImporter()->beginImport([$this->makeNzbFile('unkept-insert')]);
        $this->duplicateImporter()->beginImport(
            [$this->makeImportableNzbFile('Existing.Release yEnc (1/1)')],
            releaseName: 'Existing.Release',
        );

        $this->assertSame(0, DB::table('kept_releases')->count());
    }

    /**
     * @param  list<array<string, mixed>>|null  $results
     * @return \Closure(array<string, mixed>): void
     */
    private function collectResults(?array &$results): \Closure
    {
        $results = [];

        return static function (array $result) use (&$results): void {
            $results[] = $result;
        };
    }

    /**
     * An importer that scans for real and records what reaches `insertNZB()`.
     */
    private function recordingImporter(BlacklistService $blacklist): NzbImportService
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            /** @var list<array<string, mixed>> */
            public array $inserted = [];

            public function useBlacklist(BlacklistService $blacklist): void
            {
                $this->blacklistService = $blacklist;
            }

            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];

                return true;
            }

            protected function insertNZB(mixed $nzbDetails): NzbImportStatus
            {
                $this->inserted[] = $nzbDetails;

                return NzbImportStatus::Failed;
            }
        };
        $service->useBlacklist($blacklist);

        return $service;
    }

    /**
     * An importer that scans and inserts for real, so the duplicate finder runs.
     */
    private function duplicateImporter(): NzbImportService
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                $this->allGroups = ['alt.test' => 1];
                $this->blacklistService = new NeverBlacklistedService;

                return true;
            }
        };

        return $service;
    }

    /**
     * An importer whose scan inserts release 7 and whose compressed store succeeds.
     */
    private function insertingImporter(): NzbImportService
    {
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('kept-import-nzb').'/']);

        return new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $this->relGuid = str_repeat('7', 40);
                $this->relId = 7;

                return NzbImportStatus::Inserted;
            }

            protected function writeCompressedNzb(string $path, string $contents): bool
            {
                return true;
            }
        };
    }

    private function bindAbsorber(?DuplicateAbsorbResult $result): void
    {
        $absorber = Mockery::mock(ReleaseDuplicateAbsorber::class);
        $absorber->shouldReceive('supportsReason')->andReturn($result !== null);
        if ($result !== null) {
            $absorber->shouldReceive('absorbXml')->once()->andReturn($result);
        } else {
            $absorber->shouldNotReceive('absorbXml');
        }
        $this->app->instance(ReleaseDuplicateAbsorber::class, $absorber);
    }

    private function createDuplicateTables(): void
    {
        ProductionTables::fromAuthority()->create('releases', [
            'id', 'guid', 'name', 'searchname', 'searchname_normalized', 'fromname', 'size', 'predb_id',
            'completion', 'totalpart', 'declaredfiles', 'nzbstatus',
        ]);
        ProductionTables::fromAuthority()->create('predb', ['id', 'title', 'filename']);
        DB::table('releases')->insert([
            'id' => 41,
            'guid' => str_repeat('e', 40),
            'name' => 'Existing.Release yEnc',
            'searchname' => 'Existing.Release',
            'searchname_normalized' => ReleaseNameNormalizer::normalize('Existing.Release'),
            'fromname' => 'poster@example.test',
            'size' => 1000,
            'predb_id' => 0,
            'completion' => 100,
            'totalpart' => 1,
            'declaredfiles' => 1,
            'nzbstatus' => 1,
        ]);
    }

    private function createKeptReleaseTables(bool $withReleases = true): void
    {
        if ($withReleases) {
            ProductionTables::fromAuthority()->create('releases', ['id', 'guid']);
            DB::table('releases')->insert(['id' => 7, 'guid' => str_repeat('7', 40)]);
        }
        ProductionTables::fromAuthority()->create('kept_releases');
    }

    private function createBlacklistTables(): void
    {
        ProductionTables::fromAuthority()->create('usenet_groups', ['id', 'name']);
        ProductionTables::fromAuthority()->create('binaryblacklist', ['id', 'groupname', 'regex', 'msgcol', 'optype', 'status', 'description', 'last_activity']);
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        $this->registerSqliteFunction(
            'REGEXP',
            static fn (?string $pattern, ?string $subject): int => $pattern !== null && $subject !== null
                && preg_match('/'.str_replace('/', '\/', $pattern).'/i', $subject) === 1 ? 1 : 0,
            2
        );
    }

    private function makeImportableNzbFile(string $subject, string $group = 'alt.test'): string
    {
        $path = $this->makeTempPath('importable', '.nzb');
        file_put_contents($path, '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
            .'<file poster="poster@example.test" date="1700000000" subject="'.htmlspecialchars($subject, ENT_QUOTES).'">'
            .'<groups><group>'.htmlspecialchars($group, ENT_QUOTES).'</group></groups>'
            .'<segments><segment bytes="1000" number="1">'.bin2hex(random_bytes(6)).'@example.test</segment></segments>'
            .'</file></nzb>');

        return $path;
    }

    /**
     * The details `scanNZBFile()` hands to `insertNZB()` for an NZB holding these subjects.
     *
     * @param  list<string>  $subjects
     * @return array<string, mixed>
     */
    private function scannedDetails(array $subjects): array
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            /** @var array<string, mixed> */
            public array $details = [];

            public function scan(\SimpleXMLElement $nzb): NzbImportStatus
            {
                $this->allGroups = ['alt.binaries.test' => 1];
                $this->blacklistService = new NeverBlacklistedService;

                return $this->scanNZBFile($nzb);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function insertNZB(mixed $nzbDetails): NzbImportStatus
            {
                $this->details = $nzbDetails;

                return NzbImportStatus::Inserted;
            }
        };

        $nzb = simplexml_load_string(PhantomTrailingSets::nzb($subjects));
        $this->assertNotFalse($nzb);
        $this->assertSame(NzbImportStatus::Inserted, $service->scan($nzb));

        return $service->details;
    }

    private function makeNzbFile(string $suffix): string
    {
        $path = sys_get_temp_dir().'/'.$suffix.'-'.bin2hex(random_bytes(5)).'.nzb';
        file_put_contents($path, '<nzb></nzb>');

        return $path;
    }

    private function insertCategory(int $id, string $title, int $status): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'title' => $title,
            'status' => $status,
        ]);
    }

    private function resolveNzbCategory(string $headMetadata, string $nzbAttributes = ''): ?int
    {
        return $this->resolveNzbXml("<nzb{$nzbAttributes}><head>{$headMetadata}</head></nzb>");
    }

    private function resolveNzbXml(string $xml): ?int
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function resolveForTest(\SimpleXMLElement $nzb): ?int
            {
                return $this->resolveNzbCategoryId($nzb);
            }
        };

        $nzb = simplexml_load_string($xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, $nzb);

        return $service->resolveForTest($nzb);
    }
}
