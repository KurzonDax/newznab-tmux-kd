<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NumberedMkvArchiveTestCase;

class NumberedMkvArchiveMigrationTest extends NumberedMkvArchiveTestCase
{
    public function test_stock_upgrade_and_rollback_refresh_same_and_fresh_cached_readers(): void
    {
        $seeded = DB::table('collection_regexes')->orderBy('id')->get()->toJson();
        $this->installPrevious();
        $previous = DB::table('collection_regexes')->orderBy('id')->get()->toJson();
        $reader = new CollectionsCleaningService;
        $subject = '[02/05] - "Example.mkv.part01.rar" yEnc';
        $this->assertSame(['id' => 113, 'name' => 'Example.mkv'],
            $reader->collectionsCleaner($subject, 'alt.binaries.boneless'));

        foreach (['up' => 'Example', 'down' => 'Example.mkv'] as $direction => $expected) {
            $revision = Cache::get('collection_regexes_revision');
            $this->migration()->{$direction}();
            $this->assertNotSame($revision, Cache::get('collection_regexes_revision'));
            $this->assertSame($direction === 'up' ? $seeded : $previous,
                DB::table('collection_regexes')->orderBy('id')->get()->toJson());
            foreach ([$reader, new CollectionsCleaningService] as $cleaner) {
                $this->assertSame(['id' => 113, 'name' => $expected],
                    $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless'));
            }
            $revision = Cache::get('collection_regexes_revision');
            $this->migration()->{$direction}();
            $this->assertSame($revision, Cache::get('collection_regexes_revision'));
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>|null}> */
    public static function localRows(): iterable
    {
        foreach (['up', 'down'] as $direction) {
            foreach (['missing' => null, 'disabled' => ['status' => 0], 'moved' => ['ordinal' => 89],
                'different owner' => ['group_regex' => '^alt\\.binaries\\.other$'],
                'owner case' => ['group_regex' => '^Alt\\.binaries\\.boneless$'],
                'owner whitespace' => ['group_regex' => '^alt\\.binaries\\.boneless$ '],
                'custom regex' => ['regex' => '~custom~'], 'different id' => ['id' => 99999],
            ] as $name => $changes) {
                yield $direction.' '.$name => [$direction, $changes];
            }
        }
    }

    /** @param array<string, mixed>|null $changes */
    #[DataProvider('localRows')]
    public function test_local_rows_are_preserved(string $direction, ?array $changes): void
    {
        if ($direction === 'up') {
            $this->installPrevious();
        }
        if ($changes === null) {
            DB::table('collection_regexes')->where('id', 113)->delete();
        } else {
            DB::table('collection_regexes')->where('id', 113)->update($changes);
        }
        Cache::forever('collection_regexes_revision', 'unchanged');
        // An in-flight posting must not turn an ineligible row's no-op into an error.
        $this->ingest(array_slice($this->archiveHeaders(), 0, 1));
        $before = $this->snapshot();
        $this->migration()->{$direction}();
        $this->migration()->{$direction}();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('unchanged', Cache::get('collection_regexes_revision'));
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function cohortStates(): iterable
    {
        foreach (['up', 'down'] as $direction) {
            foreach ([...array_column(CollectionFileCheckStatus::cases(), 'value'), 10] as $state) {
                foreach ([false, true] as $par2First) {
                    yield $direction.' '.$state.' '.(int) $par2First => [$direction, $state, $par2First];
                }
            }
        }
    }

    #[DataProvider('cohortStates')]
    public function test_existing_collections_do_not_block_either_direction(string $direction, int $state, bool $par2First): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        if ($direction === 'up') {
            $this->installPrevious();
        }
        $headers = $this->archiveHeaders();
        $this->ingest([$headers[$par2First ? 0 : 2]]);
        DB::table('collections')->update(['filecheck' => $state, 'releases_id' => $state === 4 ? 77 : null]);
        $before = $this->snapshot(false);
        Cache::forever('collection_regexes_revision', 'before');

        $this->migration()->{$direction}();

        $this->assertSame($direction === 'up' ? $updated : self::PREVIOUS, DB::table('collection_regexes')->where('id', 113)->value('regex'));
        $this->assertNotSame('before', Cache::get('collection_regexes_revision'));
        $this->assertSame($before, $this->snapshot(false));
        $revision = Cache::get('collection_regexes_revision');
        $this->migration()->{$direction}();
        $this->assertSame($revision, Cache::get('collection_regexes_revision'));
    }

    public function test_manually_updated_stock_is_a_no_op(): void
    {
        Cache::forever('collection_regexes_revision', 'manual');
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('manual', Cache::get('collection_regexes_revision'));
    }

    public function test_ownership_read_and_update_share_a_transaction(): void
    {
        $this->installPrevious();
        $levels = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$levels): void {
            if (str_contains($sql, '"collection_regexes"')) {
                $levels[] = DB::transactionLevel();
            }
        });
        $this->migration()->up();
        $this->assertSame([1, 1], $levels);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_stock_ownership_is_checked_again_at_the_write_boundary(): void
    {
        $this->installPrevious();
        Cache::forever('collection_regexes_revision', 'unchanged');
        $interleaved = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$interleaved): void {
            if (! $interleaved && str_starts_with($sql, 'update "collection_regexes"')) {
                $interleaved = true;
                DB::table('collection_regexes')->where('id', 113)->update(['regex' => '~local edit~']);
            }
        });
        $this->migration()->up();
        $this->assertTrue($interleaved);
        $this->assertSame('~local edit~', DB::table('collection_regexes')->where('id', 113)->value('regex'));
        $this->assertSame('unchanged', Cache::get('collection_regexes_revision'));
    }

    public function test_a_local_description_is_not_a_stock_ownership_field(): void
    {
        $this->installPrevious();
        DB::table('collection_regexes')->where('id', 113)->update(['description' => 'Local explanation']);
        $this->migration()->up();
        $this->assertNotSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 113)->value('regex'));
        $this->migration()->down();
        $this->assertSame('Local explanation', DB::table('collection_regexes')->where('id', 113)->value('description'));
    }

    /** @return array<string, string> */
    private function snapshot(bool $includeRegexes = true): array
    {
        $result = [];
        foreach (['collections', 'binaries', 'parts', 'releases', ...($includeRegexes ? ['collection_regexes'] : [])] as $table) {
            $result[$table] = serialize(DB::table($table)->orderBy('id')->get()->all());
        }

        return $result;
    }

    public function test_revision_changes_only_after_commit_and_never_after_transaction_rollback(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        foreach (['up' => self::PREVIOUS, 'down' => $updated] as $direction => $source) {
            DB::table('collection_regexes')->where('id', 113)->update(['regex' => $source]);
            Cache::flush();
            Cache::forever('collection_regexes_revision', 'before');
            $cleaner = new CollectionsCleaningService;
            $subject = '[02/05] - "Example.mkv.part01.rar" yEnc';
            $before = $direction === 'up' ? 'Example.mkv' : 'Example';
            $after = $direction === 'up' ? 'Example' : 'Example.mkv';
            $this->assertSame($before, $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
            DB::beginTransaction();
            try {
                $this->migration()->{$direction}();
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
                $this->assertSame($before, $cleaner->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
            } finally {
                DB::rollBack();
            }
            $this->assertSame($source, DB::table('collection_regexes')->where('id', 113)->value('regex'));
            $this->assertSame('before', Cache::get('collection_regexes_revision'));
            DB::transaction(function () use ($direction): void {
                $this->migration()->{$direction}();
                $this->assertSame('before', Cache::get('collection_regexes_revision'));
            });
            $this->assertNotSame('before', Cache::get('collection_regexes_revision'));
            foreach ([$cleaner, new CollectionsCleaningService] as $reader) {
                $this->assertSame($after, $reader->collectionsCleaner($subject, 'alt.binaries.boneless')['name']);
            }
        }
    }

    public function test_case_only_regex_edits_are_not_stock_in_either_direction(): void
    {
        $updated = DB::table('collection_regexes')->where('id', 113)->value('regex');
        foreach (['up' => self::PREVIOUS, 'down' => $updated] as $direction => $source) {
            $custom = str_replace('yEnc', 'YENC', $source);
            DB::table('collection_regexes')->where('id', 113)->update(['regex' => $custom]);
            Cache::forever('collection_regexes_revision', 'unchanged');
            $this->migration()->{$direction}();
            $this->assertSame($custom, DB::table('collection_regexes')->where('id', 113)->value('regex'));
            $this->assertSame('unchanged', Cache::get('collection_regexes_revision'));
        }
    }
}
