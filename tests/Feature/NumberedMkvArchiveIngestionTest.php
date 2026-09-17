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
use Tests\Support\NumberedMkvArchiveTestCase;
use Tests\Support\ProductionTables;

class NumberedMkvArchiveIngestionTest extends NumberedMkvArchiveTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_10_224820_create_collection_sweep_cursors_table.php'))->up();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        NzbCreationCandidateQuery::flushCapabilityCache();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('numbered-mkv-nzb')]);
        ProductionTables::fromAuthority()->create('categories', ['id', 'title', 'root_categories_id']);
        ProductionTables::fromAuthority()->create('root_categories', ['id', 'title']);
        DB::table('root_categories')->insert(['id' => 7000, 'title' => 'Misc']);
        DB::table('categories')->insert(['id' => 7010, 'title' => 'Other', 'root_categories_id' => 7000]);
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

    /** @return iterable<string, array{bool, bool}> */
    public static function postingOrders(): iterable
    {
        foreach ([false, true] as $upgraded) {
            foreach ([false, true] as $par2First) {
                yield ($upgraded ? 'upgraded' : 'seeded').' '.($par2First ? 'parity first' : 'archive first') => [$upgraded, $par2First];
            }
        }
    }

    #[DataProvider('postingOrders')]
    public function test_complete_postings_publish_every_file_and_segment_once(bool $upgraded, bool $par2First): void
    {
        if ($upgraded) {
            $this->installPrevious();
            $this->migration()->up();
        }
        $headers = $this->archiveHeaders();
        $this->ingestInBatches($headers, $par2First);
        $this->ingest(array_reverse($headers));
        $collection = DB::table('collections')->sole();
        $this->assertSame(113, (int) $collection->collection_regexes_id);
        $this->assertSame(sha1('Example5', true), $collection->collectionhash);
        $this->assertSame(5, (int) $collection->declaredfiles);
        $this->assertSame(5, DB::table('binaries')->count());
        $this->assertSame(10, DB::table('parts')->count());
        $this->assertSame([1, 2, 3, 4, 5], DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')->all());
        $this->assertStoredParts($headers);
        $this->assertSame(CollectionFileCheckStatus::Sized->value, (int) $collection->filecheck);
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $this->assertSame(['added' => 0, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $this->assertSame(5, (int) $release->declaredfiles);
        $this->assertSame(100.0, (float) $release->completion);
        $this->assertPublishedInventory($release, $headers);
        $this->assertSame(100.0, (float) $release->fresh()->completion);
        $published = app(NzbService::class)->readNzbContents($release->guid);
        $this->ingest(array_reverse($headers));
        $this->assertSame(0, app(ReleaseCreationService::class)->createReleases(1, 10, false)['added']);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame($published, app(NzbService::class)->readNzbContents($release->guid));
    }

    #[DataProvider('postingOrders')]
    public function test_missing_final_segment_remains_missing_after_quiet_time_publication(bool $upgraded, bool $par2First): void
    {
        if ($upgraded) {
            $this->installPrevious();
            $this->migration()->up();
        }
        $headers = $this->archiveHeaders();
        array_pop($headers);
        $this->ingestInBatches($headers, $par2First);
        $this->ingest(array_reverse($headers));
        $collection = DB::table('collections')->sole();
        $this->assertSame(5, (int) $collection->declaredfiles);
        $this->assertSame(sha1('Example5', true), $collection->collectionhash);
        $this->assertSame(5, DB::table('binaries')->count());
        $this->assertSame(9, DB::table('parts')->count());
        $this->assertStoredParts($headers);
        DB::table('usenet_groups')->update(['last_record_postdate' => '2026-01-01 15:00:00']);
        $processor = app(ReleaseProcessingService::class)->setEchoCLI(false);
        $processor->processIncompleteCollections(1);
        $processor->processCollectionSizes(1);
        $this->assertSame(['added' => 1, 'dupes' => 0], app(ReleaseCreationService::class)->createReleases(1, 10, false));
        $release = Release::query()->sole();
        $this->assertSame(5, (int) $release->declaredfiles);
        $this->assertSame(90.0, (float) $release->completion);
        $this->assertPublishedInventory($release, $headers);
        $this->assertSame(90.0, (float) $release->fresh()->completion);
    }

    public function test_previous_rule_splits_archives_and_parity_despite_complete_headers(): void
    {
        $this->installPrevious();
        $headers = $this->archiveHeaders();
        $this->ingestInBatches($headers, true);
        $this->ingest(array_reverse($headers));
        $this->assertSame(2, DB::table('collections')->count());
        foreach (['Example5' => 3, 'Example.mkv5' => 2] as $key => $binaries) {
            $collection = DB::table('collections')->where('collectionhash', sha1($key, true))->sole();
            $this->assertSame(113, (int) $collection->collection_regexes_id);
            $this->assertSame(5, (int) $collection->declaredfiles);
            $this->assertSame($binaries, DB::table('binaries')->where('collections_id', $collection->id)->count());
        }
        $this->assertStoredParts($headers);
    }

    /** @param list<array<string, mixed>> $headers */
    private function ingestInBatches(array $headers, bool $par2First): void
    {
        $parity = array_values(array_filter($headers, static fn (array $header): bool => str_contains($header['Subject'], '.par2')));
        $archives = array_values(array_filter($headers, static fn (array $header): bool => str_contains($header['Subject'], '.rar')));
        foreach ($par2First ? [$parity, $archives] : [$archives, $parity] as $phase) {
            foreach (array_chunk($phase, 3) as $batch) {
                $this->ingest($batch);
            }
        }
    }

    /** @param list<array<string, mixed>> $headers */
    private function assertStoredParts(array $headers): void
    {
        $expected = array_map(static fn (array $header): array => [
            $header['Number'], $header['Message-ID'], $header['Bytes'],
        ], $headers);
        $actual = DB::table('parts')->orderBy('number')->get()
            ->map(static fn (object $part): array => [(int) $part->number, $part->messageid, (int) $part->size])->all();
        $this->assertSame($expected, $actual);
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
            $this->assertSame('alt.binaries.boneless', (string) $file->groups->group);
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
