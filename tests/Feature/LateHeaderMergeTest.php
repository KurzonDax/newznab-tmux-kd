<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\ReleaseRepair\NzbRepairDocument;
use App\Services\Releases\LateHeaderMerger;
use Database\Seeders\CollectionRegexesTableSeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\NeverBlacklistedService;
use Tests\Support\ProductionTables;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\Support\SumsNzbSegmentBytes;
use Tests\TestCase;

class LateHeaderMergeTest extends TestCase
{
    use CreatesPostingSchema;
    use SumsNzbSegmentBytes;

    private const int FILES = 5;

    private const int SEGMENTS = 10;

    private const string POSTER = 'Fixture Poster <poster@example.invalid>';

    /** The group frontier once the release is formed: a day past the post. */
    private const string FRONTIER = '2026-09-02 12:00:00';

    /** @var list<string> */
    private array $duplicateReasons = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('late-header-merge-nzb')]);
        DB::purge();
        DB::reconnect();
        $this->registerSqliteFunction('regexp', static fn (string $pattern, string $value): int => preg_match('/'.str_replace('/', '\\/', $pattern).'/i', $value) === 1 ? 1 : 0, 2);
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        $this->createPostingSchema();
        // Columns the evidence-changed transition resets, beyond the shared posting schema.
        foreach (['proc_nfo', 'proc_files', 'proc_srr', 'proc_crc32', 'proc_uid', 'proc_hash16k', 'proc_par2', 'proc_srrdb',
            'proc_xxx', 'proc_media_movie', 'pp_timeout_count'] as $column) {
            DB::statement("ALTER TABLE releases ADD {$column} INTEGER NOT NULL DEFAULT 0");
        }
        (require database_path('migrations/2026_09_10_134847_add_reconciliation_admissions.php'))->up();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        $this->seed(CollectionRegexesTableSeeder::class);
        Cache::flush();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        NzbCreationCandidateQuery::flushCapabilityCache();
        $tables = ProductionTables::fromAuthority();
        foreach (['categories', 'root_categories', 'predb', 'release_regexes', 'release_naming_regexes'] as $table) {
            $tables->create($table);
        }
        DB::table('root_categories')->insert(['id' => 0, 'title' => 'Other']);
        DB::table('categories')->insert(['id' => 10, 'title' => 'Misc', 'root_categories_id' => 0]);
        foreach (['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1', 'check_passworded_rars' => '0', 'delaytime' => '1'] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
        Event::listen(MessageLogged::class, function (MessageLogged $message): void {
            if ($message->message === 'Release import skipped as duplicate') {
                $this->duplicateReasons[] = (string) ($message->context['reason'] ?? '');
            }
        });
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        parent::tearDown();
    }

    public function test_missing_segments_are_filled_from_a_late_collection(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $this->assertLessThan(100.0, (float) $release->completion);
        $formedSize = (int) $release->size;
        $lateId = $this->ingestLate([1 => range(1, self::SEGMENTS)]);

        $result = $this->formReleases();

        $document = $this->storedDocument($release);
        $segments = $document->segments()[0];
        $this->assertSame(range(1, self::SEGMENTS), array_keys($segments));
        $this->assertSame($this->messageId(1, 3), $segments[3]);
        $this->assertSame(range(1, self::SEGMENTS), $this->segmentOrderInXml($release, 0));
        $release->refresh();
        $declared = (int) $release->declaredfiles;
        $this->assertSame($document->measure($declared)->percentage(), (float) $release->completion);
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertSame($document->fileCount(), (int) $release->totalpart);
        $this->assertSame($this->nzbSegmentBytes((string) app(NzbService::class)->readNzbContents($release->guid)), (int) $release->size);
        $this->assertGreaterThan($formedSize, (int) $release->size);
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(0, $result['dupes']);
    }

    public function test_a_missing_whole_file_is_appended(): void
    {
        $release = $this->publishRelease(missing: [4 => range(1, self::SEGMENTS)]);
        $formedSize = (int) $release->size;
        $lateId = $this->ingestLate([4 => range(1, self::SEGMENTS)]);

        $this->formReleases();

        $document = $this->storedDocument($release);
        $this->assertSame(self::FILES, $document->fileCount());
        $this->assertContains($this->subject(4), $document->subjects());
        $appended = array_search($this->subject(4), $document->subjects(), true);
        $this->assertSame(range(1, self::SEGMENTS), array_keys($document->segments()[$appended]));
        $release->refresh();
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertSame($this->nzbSegmentBytes((string) app(NzbService::class)->readNzbContents($release->guid)), (int) $release->size);
        $this->assertGreaterThan($formedSize, (int) $release->size);
        $this->assertLateCollectionGone($lateId);
    }

    public function test_a_missing_file_is_not_appended_when_a_held_file_has_no_index(): void
    {
        $release = $this->publishRelease(missing: [4 => range(1, self::SEGMENTS)]);
        $nzbs = app(NzbService::class);
        $stored = (string) $nzbs->readNzbContents($release->guid);
        $unindexed = str_replace('Fixture.Post [02/5] - ', 'Fixture.Post - ', $stored);
        $this->assertNotSame($stored, $unindexed);
        $this->assertTrue($nzbs->replaceNzbContents($release->guid, $unindexed)->success);
        $before = $nzbs->readNzbContents($release->guid);
        $lateId = $this->ingestLate([4 => range(1, self::SEGMENTS)]);

        $result = $this->formReleases();

        $this->assertSame($before, $nzbs->readNzbContents($release->guid));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, $result['dupes']);
        $this->assertSame(['collectionhash_match'], $this->duplicateReasons);
    }

    public function test_a_complete_release_is_not_touched(): void
    {
        $release = $this->publishRelease(missing: []);
        $this->assertSame(100.0, (float) $release->completion);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $lateId = $this->ingestLate([1 => range(1, self::SEGMENTS)]);

        $result = $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, $result['dupes']);
        $this->assertSame(['collectionhash_match'], $this->duplicateReasons);
    }

    public function test_a_different_post_with_the_same_name_is_not_merged(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $lateId = $this->ingestLate([1 => range(1, self::SEGMENTS)], otherMessageIds: [1 => [2]]);

        $result = $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, $result['dupes']);
        $this->assertSame(['collectionhash_match'], $this->duplicateReasons);
    }

    public function test_a_different_posting_sharing_the_collection_hash_is_not_merged(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $lateId = $this->insertLateCollection($release, 'Other.Post [19/83] - "other.part18.rar" yEnc', 83, [1 => '<other-1@example.invalid>']);

        $result = $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, $result['dupes']);
        $this->assertSame(['collectionhash_match'], $this->duplicateReasons);
    }

    public function test_a_late_collection_already_held_is_deleted_without_rewriting_the_nzb(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $lateId = $this->ingestLate([2 => range(1, self::SEGMENTS)]);

        $result = $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(0, $result['dupes']);
    }

    public function test_a_late_collection_waits_while_the_release_is_leased_elsewhere(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        DB::table('releases')->where('id', $release->id)->update(['recovery_claimed_at' => now(), 'recovery_claim_token' => 'elsewhere']);
        $lateId = $this->ingestLate([1 => range(1, self::SEGMENTS)]);

        $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertSame(self::SEGMENTS, $this->lateParts($lateId));
    }

    public function test_a_late_collection_waits_while_the_nzb_is_unreadable_and_the_lease_is_released(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $path = app(NzbService::class)->nzbPath($release->guid);
        $this->assertIsString($path);
        unlink($path);
        $lateId = $this->ingestLate([1 => range(1, self::SEGMENTS)]);

        $this->formReleases();

        $this->assertSame(self::SEGMENTS, $this->lateParts($lateId));
        $this->assertNull(DB::table('releases')->where('id', $release->id)->value('recovery_claimed_at'));
    }

    public function test_headers_arriving_mid_merge_keep_the_late_collection(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $lateId = $this->ingestLate([1 => [1, 2, 3, 4]]);
        $this->ingest([$this->header(1, 5)]);
        $merger = app(LateHeaderMerger::class);

        $this->assertFalse($merger->removeLateCollection($lateId, 4));
        $this->assertSame(5, $this->lateParts($lateId));
        $this->assertTrue($merger->removeLateCollection($lateId, 5));
        $this->assertLateCollectionGone($lateId);
        $this->assertNotNull($release->fresh());
    }

    public function test_an_obfuscation_recovery_publication_is_not_merged_into(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        ProductionTables::fromAuthority()->create('obfuscation_recovery_publications', ['id', 'releases_id', 'collections_id', 'guid', 'state']);
        DB::table('obfuscation_recovery_publications')->insert(['id' => 1, 'releases_id' => $release->id, 'guid' => $release->guid, 'state' => 'published']);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $this->ingestLate([1 => range(1, self::SEGMENTS)]);

        $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
    }

    public function test_a_late_collection_is_merged_after_fifteen_quiet_minutes(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $lateId = $this->ingestLateWaiting([1 => range(1, self::SEGMENTS)], quietMinutes: 16);

        $result = $this->formReleases();

        $this->assertSame(range(1, self::SEGMENTS), array_keys($this->storedDocument($release)->segments()[0]));
        $this->assertLateCollectionGone($lateId);
        $this->assertSame(0, $result['dupes']);
    }

    public function test_a_late_collection_is_not_merged_before_fifteen_quiet_minutes(): void
    {
        $release = $this->publishRelease(missing: [1 => [3, 7]]);
        $before = app(NzbService::class)->readNzbContents($release->guid);
        $lateId = $this->ingestLateWaiting([1 => range(1, self::SEGMENTS)], quietMinutes: 14);

        $this->formReleases();

        $this->assertSame($before, app(NzbService::class)->readNzbContents($release->guid));
        $this->assertNotNull(DB::table('collections')->where('id', $lateId)->first());
        $this->assertSame(self::SEGMENTS, $this->lateParts($lateId));
    }

    public function test_a_late_duplicate_is_deleted_after_fifteen_quiet_minutes(): void
    {
        $this->publishRelease(missing: []);
        $lateId = $this->ingestLateWaiting([1 => range(1, self::SEGMENTS)], quietMinutes: 16);

        $result = $this->formReleases();

        $this->assertLateCollectionGone($lateId);
        $this->assertSame(1, $result['dupes']);
        $this->assertSame(['collectionhash_match'], $this->duplicateReasons);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_a_late_duplicate_is_kept_before_fifteen_quiet_minutes(): void
    {
        $this->publishRelease(missing: []);
        $lateId = $this->ingestLateWaiting([1 => range(1, self::SEGMENTS)], quietMinutes: 14);

        $this->formReleases();

        $this->assertNotNull(DB::table('collections')->where('id', $lateId)->first());
        $this->assertSame(self::SEGMENTS, $this->lateParts($lateId));
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_a_new_post_keeps_the_frontier_wait(): void
    {
        $this->ingest(array_map(fn (int $segment): array => $this->header(1, $segment), range(1, self::SEGMENTS)));
        $collectionId = $this->holdAtFrontier(quietMinutes: 180);

        $this->formReleases();

        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) DB::table('collections')->where('id', $collectionId)->value('filecheck'));
        $this->assertSame(0, DB::table('releases')->count());
    }

    /**
     * Ingest the post less the given segments, form its release and write its NZB.
     *
     * @param  array<int, list<int>>  $missing  File index => segment numbers left out.
     */
    private function publishRelease(array $missing): Release
    {
        $headers = [];
        foreach (range(1, self::FILES) as $file) {
            foreach (range(1, self::SEGMENTS) as $segment) {
                if (! in_array($segment, $missing[$file] ?? [], true)) {
                    $headers[] = $this->header($file, $segment);
                }
            }
        }
        $this->ingest($headers);
        $this->assertSame(1, DB::table('collections')->count());

        DB::table('usenet_groups')->update(['last_record_postdate' => self::FRONTIER]);
        $processor = app(ReleaseProcessingService::class)->setEchoCLI(false);
        $processor->processIncompleteCollections(1);
        $processor->processCollectionSizes(1);
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $created = app(NzbService::class)->createNzbForRelease($release);
        $this->assertTrue($created->success, $created->reason);
        $this->assertSame(0, DB::table('collections')->count());

        return $release->fresh();
    }

    /**
     * Ingest late headers for the same post and mark their collection sized.
     *
     * @param  array<int, list<int>>  $segments  File index => segment numbers.
     * @param  array<int, list<int>>  $otherMessageIds  File index => segments carrying another post's message-ID.
     */
    private function ingestLate(array $segments, array $otherMessageIds = []): int
    {
        $this->ingest($this->lateHeaders($segments, $otherMessageIds));
        $this->assertSame(1, DB::table('collections')->count());
        DB::table('collections')->update(['filecheck' => CollectionFileCheckStatus::Sized->value]);

        return (int) DB::table('collections')->value('id');
    }

    /**
     * Ingest late headers for the same post and leave their collection waiting at the group frontier.
     *
     * @param  array<int, list<int>>  $segments  File index => segment numbers.
     */
    private function ingestLateWaiting(array $segments, int $quietMinutes): int
    {
        $this->ingest($this->lateHeaders($segments));

        return $this->holdAtFrontier($quietMinutes);
    }

    /**
     * @param  array<int, list<int>>  $segments  File index => segment numbers.
     * @param  array<int, list<int>>  $otherMessageIds  File index => segments carrying another post's message-ID.
     * @return list<array<string, mixed>>
     */
    private function lateHeaders(array $segments, array $otherMessageIds = []): array
    {
        $headers = [];
        foreach ($segments as $file => $numbers) {
            foreach ($numbers as $segment) {
                $header = $this->header($file, $segment);
                if (in_array($segment, $otherMessageIds[$file] ?? [], true)) {
                    $header['Message-ID'] = '<repost-'.$file.'-'.$segment.'@example.invalid>';
                }
                $headers[] = $header;
            }
        }

        return $headers;
    }

    /**
     * Stamp the only collection's head at the group frontier, so the frontier wait still holds it,
     * and its last stored header the given minutes ago.
     */
    private function holdAtFrontier(int $quietMinutes): int
    {
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) DB::table('collections')->value('filecheck'));
        DB::table('usenet_groups')->where('id', 1)->update(['last_record_postdate' => self::FRONTIER]);
        DB::table('collections')->update([
            'last_seen_head_postdate' => self::FRONTIER,
            'last_seen_at' => now()->subMinutes($quietMinutes)->format('Y-m-d H:i:s'),
        ]);

        return (int) DB::table('collections')->value('id');
    }

    /** @param array<int, string> $messageIds Segment number => message-ID. */
    private function insertLateCollection(Release $release, string $binaryName, int $totalParts, array $messageIds): int
    {
        $id = (int) DB::table('collections')->insertGetId([
            'subject' => $binaryName, 'fromname' => self::POSTER, 'date' => '2026-09-01 12:00:00', 'groups_id' => 1,
            'totalfiles' => 1, 'declaredfiles' => 83, 'collectionhash' => $release->collectionhash,
            'dateadded' => now(), 'last_seen_at' => now(), 'added' => now(), 'filecheck' => CollectionFileCheckStatus::Sized->value,
            'filesize' => 100,
        ]);
        DB::table('collection_groups')->insert(['collections_id' => $id, 'group_name' => 'alt.binaries.boneless']);
        $binaryId = (int) DB::table('binaries')->insertGetId([
            'binaryhash' => md5('file:19', true), 'name' => $binaryName, 'collections_id' => $id,
            'totalparts' => $totalParts, 'currentparts' => count($messageIds), 'filenumber' => 19, 'partsize' => 100,
        ]);
        foreach ($messageIds as $part => $messageId) {
            DB::table('parts')->insert(['binaries_id' => $binaryId, 'number' => 0, 'messageid' => $messageId, 'partnumber' => $part, 'size' => 100]);
        }

        return $id;
    }

    /** @param list<array<string, mixed>> $headers */
    private function ingest(array $headers): void
    {
        $parsed = (new HeaderParser(new NeverBlacklistedService))->parse($headers, 'alt.binaries.boneless');
        $this->assertCount(count($headers), $parsed['headers']);
        $result = (new HeaderStorageService(config: new BinariesConfig(echoCli: false, headerChunkSize: 100)))
            ->store($parsed['headers'], ['id' => 1, 'name' => 'alt.binaries.boneless'], false);
        $this->assertSame([], $result->uniqueFailedNumbers());
    }

    /** @return array<string, mixed> */
    private function header(int $file, int $segment): array
    {
        $number = 1000 + $file * 100 + $segment;

        return [
            'Number' => $number,
            'Subject' => sprintf('Fixture.Post [%02d/%d] - "fixture.part%d.rar" yEnc (%d/%d)', $file, self::FILES, $file, $segment, self::SEGMENTS),
            'From' => self::POSTER,
            'Date' => '2026-09-01 12:00:00 +0000', 'Bytes' => 100,
            'Message-ID' => '<'.$this->messageId($file, $segment).'>',
            'Xref' => 'news.example.invalid alt.binaries.boneless:'.$number,
        ];
    }

    private function subject(int $file): string
    {
        return sprintf('Fixture.Post [%02d/%d] - "fixture.part%d.rar" yEnc (1/%d)', $file, self::FILES, $file, self::SEGMENTS);
    }

    private function messageId(int $file, int $segment): string
    {
        return 'fixture-'.$file.'-'.$segment.'@example.invalid';
    }

    /** @return array{releases: int, nzbs: int, dupes: int, iterations: int} */
    private function formReleases(): array
    {
        return app(ReleaseProcessingService::class)->setEchoCLI(false)->formReleases(1);
    }

    private function storedDocument(Release $release): NzbRepairDocument
    {
        $document = NzbRepairDocument::load((string) app(NzbService::class)->readNzbContents($release->guid));
        $this->assertNotNull($document);

        return $document;
    }

    /** @return list<int> Segment numbers of one file in document order. */
    private function segmentOrderInXml(Release $release, int $fileIndex): array
    {
        $xml = simplexml_load_string((string) app(NzbService::class)->readNzbContents($release->guid));
        $this->assertNotFalse($xml);
        $numbers = [];
        foreach ($xml->file[$fileIndex]->segments->segment as $segment) {
            $numbers[] = (int) $segment['number'];
        }

        return $numbers;
    }

    private function lateParts(int $collectionId): int
    {
        return DB::table('parts')->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
            ->where('binaries.collections_id', $collectionId)->count();
    }

    private function assertLateCollectionGone(int $collectionId): void
    {
        $this->assertNull(DB::table('collections')->where('id', $collectionId)->first());
        $this->assertSame(0, DB::table('binaries')->where('collections_id', $collectionId)->count());
        $this->assertSame(0, $this->lateParts($collectionId));
    }
}
