<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class NzbImportSegmentHashDedupeTest extends TestCase
{
    private string $nzbDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->nzbDirectory = $this->makeTempDirectory('import-dedupe').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.check_passworded_rars' => true,
            'nntmux_settings.unrar_path' => '/usr/bin/unrar',
        ]);
        $this->registerSqliteFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        $this->registerSqliteFunction(
            'REGEXP',
            static function (?string $subject, ?string $pattern): int {
                if ($subject === null || $pattern === null || $pattern === '') {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match($pattern, $subject);
                restore_error_handler();

                return $ok ? 1 : 0;
            },
            2
        );

        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();

        $this->createTables();
        $this->seedSettings();
    }

    public function test_import_persists_sorted_segment_message_id_hash(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => '[1/2] Hashed.Import.Release.part01.rar yEnc (1/2)', 'segments' => ['seg-b@example.com', 'seg-a@example.com']],
            ['subject' => '[2/2] Hashed.Import.Release.part02.rar yEnc (1/1)', 'segments' => ['seg-c@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $expected = sha1("seg-a@example.com\nseg-b@example.com\nseg-c@example.com", true);
        $this->assertSame($expected, DB::table('releases')->value('collectionhash'));
        $this->assertSame(100.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(2, DB::table('releases')->value('declaredfiles'));
        $this->assertNull(DB::table('releases')->value('firstarticle'));
        $this->assertNull(DB::table('releases')->value('lastarticle'));
    }

    public function test_import_measures_partial_nzb_without_nfo_processing(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => '[1/2] Partial.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['part1-1@example.com', 'part1-2@example.com']],
            ['subject' => '[2/2] Partial.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['part2-1@example.com', 'part2-2@example.com', 'part2-3@example.com', 'part2-4@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $this->assertSame(75.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(2, DB::table('releases')->value('declaredfiles'));
    }

    public function test_import_without_declared_totals_keeps_the_never_measured_sentinel(): void
    {
        $status = $this->scan($this->makeNzb([
            ['subject' => 'Unmeasurable.Import.Release', 'segments' => ['unknown-total@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Inserted, $status);
        $this->assertSame(0.0, (float) DB::table('releases')->value('completion'));
        $this->assertSame(0, DB::table('releases')->value('declaredfiles'));
    }

    public function test_reimport_with_rewritten_subject_is_duplicate_via_hash(): void
    {
        $first = $this->scan($this->makeNzb([
            ['subject' => 'Original.Subject.Release', 'segments' => ['s1@example.com', 's2@example.com']],
        ]));
        $this->assertSame(NzbImportStatus::Inserted, $first);

        // Re-generated NZB for the same upload: subject rewritten (so the
        // heuristic finder cannot match on searchname) and segments listed in
        // a different order (hash must be order-invariant).
        $second = $this->scan($this->makeNzb([
            ['subject' => 'Rewritten.Different.Subject', 'segments' => ['s2@example.com', 's1@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Duplicate, $second);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_a_collectionhash_duplicate_reports_and_keeps_the_matched_release(): void
    {
        ProductionTables::fromAuthority()->create('kept_releases');
        $first = $this->import($this->makeNzb([
            ['subject' => 'Original.Hashed.Release', 'segments' => ['h1@example.com', 'h2@example.com']],
        ]));
        $this->assertSame(NzbImportStatus::Inserted, $first['status']);
        $existing = Release::query()->firstOrFail();

        // Rewritten subject: only the segment hash can match it.
        $second = $this->import($this->makeNzb([
            ['subject' => 'Rewritten.Hashed.Subject', 'segments' => ['h2@example.com', 'h1@example.com']],
        ]), keepRelease: true);

        $this->assertSame(NzbImportStatus::Duplicate, $second['status']);
        $this->assertSame((int) $existing->id, $second['release_id']);
        $this->assertSame((string) $existing->guid, $second['release_guid']);
        $this->assertFalse($second['absorbed']);
        $this->assertNull($second['absorb_outcome'], 'The collectionhash path attempts no absorb.');
        $this->assertSame([(int) $existing->id], DB::table('kept_releases')->pluck('releases_id')->map(intval(...))->all());
    }

    public function test_reimport_of_identical_nzb_is_duplicate(): void
    {
        $nzbFiles = [['subject' => 'Identical.Reimport.Release', 'segments' => ['x1@example.com', 'x2@example.com']]];

        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($nzbFiles)));
        $this->assertSame(NzbImportStatus::Duplicate, $this->scan($this->makeNzb($nzbFiles)));
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_more_complete_same_name_import_is_absorbed_into_the_existing_release(): void
    {
        $partial = $this->makeNzb([
            [
                'subject' => '[1/1] Better.Repost.part01.rar yEnc (1/20)',
                'segments' => array_map(static fn (int $part): string => "old-{$part}@example.test", range(1, 19)),
            ],
        ]);
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($partial));

        $anchor = Release::query()->firstOrFail();
        $anchorId = (int) $anchor->id;
        $anchorGuid = (string) $anchor->guid;
        $nzb = app(NzbService::class);
        file_put_contents($nzb->getNzbPath($anchorGuid, 0, true), gzencode((string) $partial->asXML()));

        $complete = $this->makeNzb([
            [
                'subject' => '[1/1] Better.Repost.part01.rar yEnc (1/20)',
                'segments' => array_map(static fn (int $part): string => "new-{$part}@example.test", range(1, 20)),
            ],
        ]);
        $this->assertSame(NzbImportStatus::Duplicate, $this->scan($complete));

        $stored = Release::query()->firstOrFail();
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($anchorId, (int) $stored->id);
        $this->assertSame($anchorGuid, (string) $stored->guid);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(20_000, (int) $stored->size);
        $this->assertSame(1, (int) $stored->totalpart);
        $this->assertSame(1, (int) $stored->declaredfiles);
        $this->assertSame(0, (int) $stored->proc_files);

        $contents = $nzb->readNzbContents($anchorGuid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new-20@example.test', $contents);
        $this->assertStringNotContainsString('old-1@example.test', $contents);
    }

    public function test_a_more_complete_predb_id_match_is_absorbed_into_the_existing_release(): void
    {
        [$anchorId, $anchorGuid] = $this->importPredbAnchor('Predb.Repost', presentSegments: 19);
        Log::spy();

        $result = $this->import($this->predbRepostNzb('Predb.Repost', 'new', presentSegments: 20));

        $this->assertDuplicateReason('predb_id_match');
        $this->assertSame(NzbImportStatus::Duplicate, $result['status']);
        $this->assertTrue($result['absorbed']);
        $this->assertSame('absorbed', $result['absorb_outcome']);
        $this->assertSame($anchorId, $result['release_id']);

        $stored = Release::query()->firstOrFail();
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($anchorId, (int) $stored->id);
        $this->assertSame($anchorGuid, (string) $stored->guid);
        $this->assertSame(100.0, (float) $stored->completion);
        $this->assertSame(20_000, (int) $stored->size);

        $contents = app(NzbService::class)->readNzbContents($anchorGuid);
        $this->assertIsString($contents);
        $this->assertStringContainsString('new-20@example.test', $contents);
        $this->assertStringNotContainsString('old-1@example.test', $contents);
    }

    public function test_an_equally_complete_predb_id_match_is_not_better_and_leaves_the_release_unchanged(): void
    {
        [$anchorId, $anchorGuid] = $this->importPredbAnchor('Predb.Equal', presentSegments: 19);
        $nzb = app(NzbService::class);
        $storedXml = $nzb->readNzbContents($anchorGuid);
        Log::spy();

        $result = $this->import($this->predbRepostNzb('Predb.Equal', 'new', presentSegments: 19));

        $this->assertDuplicateReason('predb_id_match');
        $this->assertSame(NzbImportStatus::Duplicate, $result['status']);
        $this->assertFalse($result['absorbed']);
        $this->assertSame('not_better', $result['absorb_outcome']);

        $stored = Release::query()->firstOrFail();
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($anchorId, (int) $stored->id);
        $this->assertSame(95.0, (float) $stored->completion);
        $this->assertSame(19_000, (int) $stored->size);
        $this->assertSame($storedXml, $nzb->readNzbContents($anchorGuid));
    }

    public function test_a_predb_id_match_against_an_anchor_without_a_stored_nzb_reports_deferred(): void
    {
        [$anchorId] = $this->importPredbAnchor('Predb.Lagging', presentSegments: 19);
        DB::table('releases')->update(['nzbstatus' => NzbService::NZB_NONE]);
        Log::spy();

        $result = $this->import($this->predbRepostNzb('Predb.Lagging', 'new', presentSegments: 20));

        $this->assertDuplicateReason('predb_id_match');
        $this->assertSame(NzbImportStatus::Duplicate, $result['status']);
        $this->assertFalse($result['absorbed']);
        $this->assertSame('deferred', $result['absorb_outcome']);

        $stored = Release::query()->firstOrFail();
        $this->assertSame(1, Release::query()->count());
        $this->assertSame($anchorId, (int) $stored->id);
        $this->assertSame(95.0, (float) $stored->completion);
        $this->assertSame(19_000, (int) $stored->size);
    }

    public function test_zero_segment_nzbs_get_null_hash_and_do_not_collide(): void
    {
        $first = $this->scan($this->makeNzb([['subject' => 'Empty.Segments.One', 'segments' => []]]));
        $second = $this->scan($this->makeNzb([['subject' => 'Empty.Segments.Two', 'segments' => []]]));

        $this->assertSame(NzbImportStatus::Inserted, $first);
        $this->assertSame(NzbImportStatus::Inserted, $second);
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertSame(2, DB::table('releases')->whereNull('collectionhash')->count());
    }

    public function test_distinct_nzbs_both_insert(): void
    {
        $first = $this->scan($this->makeNzb([['subject' => 'Distinct.Release.One', 'segments' => ['d1@example.com']]]));
        $second = $this->scan($this->makeNzb([['subject' => 'Distinct.Release.Two', 'segments' => ['d2@example.com']]]));

        $this->assertSame(NzbImportStatus::Inserted, $first);
        $this->assertSame(NzbImportStatus::Inserted, $second);
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertSame(
            2,
            DB::table('releases')->whereNotNull('collectionhash')->distinct()->count('collectionhash')
        );
    }

    public function test_a_deferred_absorb_records_the_import_as_an_ordinary_duplicate(): void
    {
        $files = [
            ['subject' => '[1/2] Deferred.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['d1-1@example.com', 'd1-2@example.com']],
            ['subject' => '[2/2] Deferred.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['d2-1@example.com', 'd2-2@example.com', 'd2-3@example.com', 'd2-4@example.com']],
        ];
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($files)));

        // The lagging-anchor state: the release row exists but its stored NZB
        // does not, and a better copy of the same upload arrives.
        DB::table('releases')->update(['nzbstatus' => NzbService::NZB_NONE, 'completion' => 10.0]);

        $status = $this->scan($this->makeNzb($files));

        $this->assertSame(NzbImportStatus::Duplicate, $status);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(
            10.0,
            (float) DB::table('releases')->value('completion'),
            'A deferred absorb must leave the anchor untouched.'
        );
    }

    public function test_a_failed_absorb_records_the_import_as_an_ordinary_duplicate_with_the_reason(): void
    {
        $files = [
            ['subject' => '[1/2] Failing.Import.Release.part01.rar yEnc (1/4)', 'segments' => ['f1-1@example.com', 'f1-2@example.com']],
            ['subject' => '[2/2] Failing.Import.Release.part02.rar yEnc (1/4)', 'segments' => ['f2-1@example.com', 'f2-2@example.com', 'f2-3@example.com', 'f2-4@example.com']],
        ];
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($this->makeNzb($files)));

        // nzbstatus says NZB_ADDED but no stored file exists in this harness:
        // the absorb is attempted and fails on the missing NZB.
        DB::table('releases')->update(['completion' => 10.0]);
        Log::spy();

        $status = $this->scan($this->makeNzb($files));

        $this->assertSame(NzbImportStatus::Duplicate, $status);
        $this->assertSame(
            10.0,
            (float) DB::table('releases')->value('completion'),
            'A failed absorb must leave the anchor untouched.'
        );
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'absorb failed')
                && str_contains((string) ($context['reason'] ?? ''), 'No stored NZB')
        );
    }

    public function test_a_failed_store_creates_no_release(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function writeCompressedNzb(string $path, string $contents): bool
            {
                return false;
            }
        };

        $result = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Unstorable.Release', 'segments' => ['u1@example.com']],
        ]))['result'];

        $this->assertSame(NzbImportStatus::Failed, $result['status']);
        $this->assertStringStartsWith('ERROR: Problem compressing NZB file to: ', (string) $result['error']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame([], $this->storedNzbFiles());
    }

    public function test_the_nzb_is_stored_before_the_release_row_is_inserted(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public ?bool $storedBeforeInsert = null;

            protected function insertReleaseRow(array $row): ?int
            {
                $this->storedBeforeInsert = File::isFile($this->nzb->getNzbPath((string) $row['guid']));

                return parent::insertReleaseRow($row);
            }
        };

        $result = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Stored.First.Release', 'segments' => ['sf1@example.com']],
        ]))['result'];

        $this->assertSame(NzbImportStatus::Inserted, $result['status']);
        $this->assertTrue($service->storedBeforeInsert);
        $this->assertFileExists(app(NzbService::class)->getNzbPath((string) $result['release_guid']));
    }

    public function test_an_insert_that_returns_null_removes_the_stored_nzb(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function insertReleaseRow(array $row): ?int
            {
                return null;
            }
        };

        $result = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Null.Insert.Release', 'segments' => ['n1@example.com']],
        ]))['result'];

        $this->assertSame(NzbImportStatus::Failed, $result['status']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame([], $this->storedNzbFiles());
    }

    public function test_an_insert_that_throws_without_a_row_removes_the_stored_nzb(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function insertReleaseRow(array $row): ?int
            {
                throw new \RuntimeException('database went away');
            }
        };

        $import = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Throwing.Insert.Release', 'segments' => ['t1@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Failed, $import['result']['status']);
        $this->assertSame('ERROR: Problem inserting: '.$import['source'], $import['result']['error']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame([], $this->storedNzbFiles());
    }

    public function test_an_insert_that_throws_after_writing_the_row_keeps_the_release_and_its_nzb(): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function insertReleaseRow(array $row): ?int
            {
                parent::insertReleaseRow($row);

                throw new \RuntimeException('search index went away');
            }
        };

        $result = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Late.Throw.Release', 'segments' => ['l1@example.com']],
        ]))['result'];

        $this->assertSame(NzbImportStatus::Failed, $result['status']);
        $release = Release::query()->firstOrFail();
        $this->assertFileExists(app(NzbService::class)->getNzbPath((string) $release->guid));
        $this->assertCount(1, $this->storedNzbFiles());
    }

    public function test_a_collectionhash_duplicate_leaves_only_the_matched_release_nzb(): void
    {
        $first = $this->import($this->makeNzb([
            ['subject' => 'Original.Stored.Release', 'segments' => ['c1@example.com', 'c2@example.com']],
        ]));
        $this->assertSame(NzbImportStatus::Inserted, $first['status']);
        $existing = Release::query()->firstOrFail();

        // Rewritten subject: only the segment hash can match it.
        $second = $this->import($this->makeNzb([
            ['subject' => 'Rewritten.Stored.Subject', 'segments' => ['c2@example.com', 'c1@example.com']],
        ]));

        $this->assertSame(NzbImportStatus::Duplicate, $second['status']);
        $existingPath = app(NzbService::class)->getNzbPath((string) $existing->guid);
        $this->assertFileExists($existingPath);
        $this->assertSame([$existingPath], $this->storedNzbFiles());
    }

    public function test_a_failed_cleanup_keeps_the_duplicate_outcome(): void
    {
        $this->import($this->makeNzb([
            ['subject' => 'Cleanup.Original.Release', 'segments' => ['k1@example.com']],
        ]));
        $service = new NzbImportService(['Browser' => true]);
        $nzb = \Mockery::mock($service->nzb);
        $nzb->shouldReceive('deleteOrphanNzb')->once()->andThrow(new \RuntimeException('lock wait timeout'));
        $service->nzb = $nzb;

        $result = $this->importWith($service, $this->makeNzb([
            ['subject' => 'Cleanup.Rewritten.Subject', 'segments' => ['k1@example.com']],
        ]))['result'];

        $this->assertSame(NzbImportStatus::Duplicate, $result['status']);
        $this->assertCount(2, $this->storedNzbFiles(), 'The undeletable file stays behind as an orphan.');
    }

    public function test_a_successful_import_deletes_its_source_and_counts_as_processed(): void
    {
        $import = $this->importWith(new NzbImportService(['Browser' => true]), $this->makeNzb([
            ['subject' => 'Counted.Release', 'segments' => ['p1@example.com']],
        ]), delete: true);

        $this->assertSame(NzbImportStatus::Inserted, $import['result']['status']);
        $this->assertStringContainsString('Processed 1 NZBs in ', (string) $import['output']);
        $this->assertFileDoesNotExist($import['source']);
        $this->assertCount(1, $this->storedNzbFiles());
    }

    /**
     * Import a partial anchor, store its NZB, and link it to a PreDB row titled with its cleaned
     * name, so a later copy of the same post resolves to that PreDB ID on import.
     *
     * @return array{0: int, 1: string} The anchor's id and guid.
     */
    private function importPredbAnchor(string $title, int $presentSegments): array
    {
        $partial = $this->predbRepostNzb($title, 'old', $presentSegments);
        $this->assertSame(NzbImportStatus::Inserted, $this->scan($partial));

        $anchor = Release::query()->firstOrFail();
        file_put_contents(app(NzbService::class)->getNzbPath((string) $anchor->guid, 0, true), gzencode((string) $partial->asXML()));
        DB::table('predb')->insert(['id' => 77, 'title' => (string) $anchor->searchname, 'filename' => '']);
        DB::table('releases')->where('id', $anchor->id)->update(['predb_id' => 77]);

        return [(int) $anchor->id, (string) $anchor->guid];
    }

    private function predbRepostNzb(string $title, string $messageIdPrefix, int $presentSegments): \SimpleXMLElement
    {
        return $this->makeNzb([[
            'subject' => "[1/1] {$title}.part01.rar yEnc (1/20)",
            'segments' => array_map(static fn (int $part): string => "{$messageIdPrefix}-{$part}@example.test", range(1, $presentSegments)),
        ]]);
    }

    private function assertDuplicateReason(string $reason): void
    {
        Log::shouldHaveReceived('info')->withArgs(
            static fn (string $message, array $context = []): bool => $message === 'NZB import skipped as duplicate'
                && ($context['reason'] ?? null) === $reason
        )->once();
    }

    private function scan(\SimpleXMLElement $nzbXML): NzbImportStatus
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function scanForTest(\SimpleXMLElement $nzbXML): NzbImportStatus
            {
                $this->getAllGroups();

                return $this->scanNZBFile($nzbXML);
            }
        };

        return $service->scanForTest($nzbXML);
    }

    /**
     * Import the NZB from a file through `beginImport()` and return its reported result.
     *
     * @return array<string, mixed>
     */
    private function import(\SimpleXMLElement $nzbXML, bool $keepRelease = false): array
    {
        return $this->importWith(new NzbImportService(['Browser' => true]), $nzbXML, keepRelease: $keepRelease)['result'];
    }

    /**
     * Import the NZB from a file through the given service's `beginImport()`.
     *
     * @return array{result: array<string, mixed>, output: bool|string, source: string}
     */
    private function importWith(
        NzbImportService $service,
        \SimpleXMLElement $nzbXML,
        bool $keepRelease = false,
        bool $delete = false,
    ): array {
        $path = $this->makeTempPath('import-dedupe', '.nzb');
        file_put_contents($path, (string) $nzbXML->asXML());
        $results = [];

        $output = $service->beginImport(
            [$path],
            delete: $delete,
            resultCallback: static function (array $result) use (&$results): void {
                $results[] = $result;
            },
            keepRelease: $keepRelease,
        );
        $this->assertCount(1, $results);

        return ['result' => $results[0], 'output' => $output, 'source' => $path];
    }

    /**
     * Every stored `.nzb.gz` under the temporary NZB root.
     *
     * @return list<string>
     */
    private function storedNzbFiles(): array
    {
        $paths = array_map(static fn (\SplFileInfo $file): string => $file->getPathname(), File::allFiles($this->nzbDirectory));

        return array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.nzb.gz')));
    }

    /**
     * @param  list<array{subject: string, segments: list<string>}>  $files
     */
    private function makeNzb(array $files): \SimpleXMLElement
    {
        $fileXml = '';
        foreach ($files as $file) {
            $segmentXml = '';
            $number = 1;
            foreach ($file['segments'] as $messageId) {
                $segmentXml .= '<segment bytes="1000" number="'.$number++.'">'
                    .htmlspecialchars($messageId, ENT_QUOTES)
                    .'</segment>';
            }
            $fileXml .= '<file poster="poster@example.com" date="1700000000" subject="'
                .htmlspecialchars($file['subject'], ENT_QUOTES).'">'
                .'<groups><group>alt.test</group></groups>'
                .'<segments>'.$segmentXml.'</segments>'
                .'</file>';
        }

        $nzb = simplexml_load_string(
            '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'.$fileXml.'</nzb>'
        );
        $this->assertInstanceOf(\SimpleXMLElement::class, $nzb);

        return $nzb;
    }

    private function seedSettings(): void
    {
        $settings = [
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
            'crossposttime' => '2',
        ];

        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255) UNIQUE)');
        DB::statement('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255),
            status INTEGER DEFAULT 1
        )');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            searchname_normalized VARCHAR(255),
            display_name VARCHAR(255),
            totalpart INTEGER,
            declaredfiles INTEGER NULL,
            firstarticle INTEGER NULL,
            lastarticle INTEGER NULL,
            groups_id INTEGER,
            adddate DATETIME NULL,
            guid VARCHAR(64) UNIQUE,
            leftguid VARCHAR(1),
            postdate DATETIME NULL,
            fromname VARCHAR(255),
            size INTEGER,
            passwordstatus INTEGER,
            haspreview INTEGER,
            categories_id INTEGER,
            nfostatus INTEGER,
            nzbstatus INTEGER,
            completion DOUBLE NOT NULL DEFAULT 0,
            pp_timeout_count INTEGER NOT NULL DEFAULT 0,
            proc_nfo INTEGER NOT NULL DEFAULT 0,
            proc_files INTEGER NOT NULL DEFAULT 0,
            proc_srr INTEGER NOT NULL DEFAULT 0,
            proc_crc32 INTEGER NOT NULL DEFAULT 0,
            proc_uid INTEGER NOT NULL DEFAULT 0,
            proc_hash16k INTEGER NOT NULL DEFAULT 0,
            proc_par2 INTEGER NOT NULL DEFAULT 0,
            proc_srrdb INTEGER NOT NULL DEFAULT 0,
            proc_xxx INTEGER NOT NULL DEFAULT 0,
            proc_media_movie INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER,
            is_trusted_name INTEGER DEFAULT 0,
            iscategorized INTEGER,
            predb_id INTEGER,
            collectionhash BLOB NULL
        )');
        DB::statement('CREATE UNIQUE INDEX ux_releases_collectionhash ON releases (collectionhash)');
        DB::statement('CREATE TABLE video_data (releases_id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE audio_data (id INTEGER PRIMARY KEY, releases_id INTEGER)');
        DB::statement('CREATE TABLE release_naming_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255) UNIQUE,
            filename VARCHAR(255)
        )');
        DB::statement('CREATE TABLE binaryblacklist (
            id INTEGER PRIMARY KEY,
            groupname VARCHAR(255) NULL,
            regex VARCHAR(2000),
            msgcol INTEGER DEFAULT 1,
            optype INTEGER DEFAULT 1,
            status INTEGER DEFAULT 1,
            description VARCHAR(1000) NULL,
            last_activity DATE NULL
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'status' => 1]);
    }
}
