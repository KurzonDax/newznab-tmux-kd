<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\TokenPrefixedArchiveTestCase;

class TokenPrefixedArchiveIngestionTest extends TokenPrefixedArchiveTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        NzbCreationCandidateQuery::flushCapabilityCache();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('token-archives-nzb')]);
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title TEXT, parent_categories_id INTEGER, root_categories_id INTEGER)');
        DB::statement('CREATE TABLE root_categories (id INTEGER PRIMARY KEY, title TEXT)');
        DB::table('root_categories')->insert(['id' => 6000, 'title' => 'XXX']);
        DB::table('categories')->insert(['id' => 6010, 'title' => 'Other', 'root_categories_id' => 6000]);
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, filename TEXT)');
        DB::statement('CREATE TABLE release_regexes (releases_id INTEGER, collection_regex_id INTEGER, naming_regex_id INTEGER)');
        DB::statement('CREATE TABLE release_naming_regexes (id INTEGER PRIMARY KEY, group_regex TEXT, regex TEXT, status INTEGER, ordinal INTEGER)');
        foreach (['categorizeforeign' => '0', 'catwebdl' => '0', 'nzbsplitlevel' => '1', 'check_passworded_rars' => '0'] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function postingFamilies(): iterable
    {
        foreach (['rar', '7z'] as $family) {
            foreach ([false, true] as $upgraded) {
                yield $family.($upgraded ? ' upgraded' : ' seeded') => [$family, $upgraded];
            }
        }
    }

    #[DataProvider('postingFamilies')]
    public function test_complete_postings_publish_every_file_and_segment_once(string $family, bool $upgraded): void
    {
        if ($upgraded) {
            $this->installPrevious();
            $this->migration()->up();
        }
        $headers = $this->archiveHeaders($family);
        $this->ingest($headers);
        $this->ingest(array_reverse($headers));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(count($this->archiveFiles($family)), DB::table('binaries')->count());
        $this->assertSame(count($headers), DB::table('parts')->count());
        $this->assertSame(CollectionFileCheckStatus::Sized->value, (int) DB::table('collections')->value('filecheck'));
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $this->assertSame(['added' => 0, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $this->assertSame(count($this->archiveFiles($family)), (int) $release->declaredfiles);
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertPublishedInventory($release, $headers);
        $this->assertSame(100.0, (float) $release->fresh()->completion);
        $published = app(NzbService::class)->readNzbContents($release->guid);
        $this->ingest(array_reverse($headers));
        $replayed = app(ReleaseCreationService::class)->createReleases(1, 10, false);
        $this->assertSame(0, $replayed['added']);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame($published, app(NzbService::class)->readNzbContents($release->guid));
    }

    /** @return iterable<string, array{string, int}> */
    public static function permutations(): iterable
    {
        foreach (['rar', '7z'] as $family) {
            for ($seed = 0; $seed < 100; $seed++) {
                yield $family.' seed '.$seed => [$family, $seed];
            }
        }
    }

    #[DataProvider('permutations')]
    public function test_header_permutations_and_duplicate_replay_preserve_the_complete_nzb(string $family, int $seed): void
    {
        $headers = (new Randomizer(new Mt19937($seed)))->shuffleArray($this->archiveHeaders($family));
        // Split across batches, so the first encountered file owns the collection subject.
        foreach (array_chunk($headers, 7) as $batch) {
            $this->ingest($batch);
        }
        $this->ingest($headers);
        $total = $family === 'rar' ? 12 : 19;
        $stem = $family === 'rar' ? 'Sample-A' : 'Sample-B';
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(sha1($total.'] - "'.$stem.$total, true), DB::table('collections')->value('collectionhash'));
        $this->assertSame($total, DB::table('binaries')->count());
        $this->assertSame($total * 2, DB::table('parts')->count());
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $this->assertSame($total, (int) $release->declaredfiles);
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertPublishedInventory($release, $headers);
    }

    #[DataProvider('postingFamilies')]
    public function test_missing_segments_are_never_manufactured_by_grouping_or_publication(string $family, bool $upgraded): void
    {
        if ($upgraded) {
            $this->installPrevious();
            $this->migration()->up();
        }
        $headers = $this->archiveHeaders($family);
        array_pop($headers);
        $this->ingest($headers);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(count($headers), DB::table('parts')->count());
        // Advance the group frontier to exercise ordinary quiet-post promotion.
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-09-01 15:00:00']);
        $processor = app(ReleaseProcessingService::class)->setEchoCLI(false);
        $processor->processIncompleteCollections(1);
        $processor->processCollectionSizes(1);
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $this->assertSame(count($this->archiveFiles($family)), (int) $release->declaredfiles);
        $this->assertEqualsWithDelta($family === 'rar' ? 95.83 : 97.37, (float) $release->completion, 0.01);
        $this->assertPublishedInventory($release, $headers);
        $this->assertLessThan(100, (float) $release->fresh()->completion);
    }

    public function test_legacy_fixtures_reproduce_two_and_nine_collections_with_eleven_payload_files(): void
    {
        foreach (['rar' => 2, '7z' => 9] as $family => $expectedCollections) {
            foreach (['parts', 'binaries', 'collection_groups', 'collections'] as $table) {
                DB::table($table)->delete();
            }
            $this->installPrevious();
            $this->ingest($this->archiveHeaders($family));
            $this->assertSame($expectedCollections, DB::table('collections')->count());
            $payloadName = $family === 'rar' ? 'Sample-A.part1.rar' : 'Sample-B.7z.001';
            $payloadCollection = DB::table('binaries')->where('name', 'like', '%"'.$payloadName.'"%')->value('collections_id');
            $this->assertSame(11, DB::table('binaries')->where('collections_id', $payloadCollection)->count());
            $key = $family === 'rar' ? '12] - "Sample-A12' : '19] - "Sample-B.7z19';
            $this->assertSame(sha1($key, true), DB::table('collections')->where('id', $payloadCollection)->value('collectionhash'));
        }
    }

    /** @param list<array<string, mixed>> $headers */
    private function assertPublishedInventory(Release $release, array $headers): void
    {
        $nzbs = app(NzbService::class);
        $result = $nzbs->createNzbForRelease($release);
        $this->assertTrue($result->success, $result->reason);
        $xml = $nzbs->readNzbContents($release->guid);
        $this->assertIsString($xml);
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document);
        $expected = [];
        foreach ($headers as $header) {
            preg_match('/^(.*) \(([12])\/2\)$/', $header['Subject'], $matches);
            $expected[$matches[1].' (1/2)'][(int) $matches[2]] = [100, trim($header['Message-ID'], '<>')];
        }
        $actual = [];
        foreach ($document->file as $file) {
            $subject = (string) $file['subject'];
            $this->assertArrayNotHasKey($subject, $actual);
            $this->assertSame('alt.binaries.erotica', (string) $file->groups->group);
            foreach ($file->segments->segment as $segment) {
                $number = (int) $segment['number'];
                $this->assertArrayNotHasKey($number, $actual[$subject] ?? []);
                $actual[$subject][$number] = [(int) $segment['bytes'], (string) $segment];
            }
            ksort($actual[$subject]);
        }
        foreach ($expected as &$segments) {
            ksort($segments);
        }
        unset($segments);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(1, DB::table('releases')->count());
    }
}
