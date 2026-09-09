<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TokenPrefixedArchiveTestCase;

class TokenPrefixedArchiveMigrationTest extends TokenPrefixedArchiveTestCase
{
    /** @return iterable<string, array{string, array<string, mixed>|null}> */
    public static function localRows(): iterable
    {
        foreach (['up', 'down'] as $direction) {
            foreach (['missing' => null, 'disabled' => ['status' => 0], 'moved' => ['ordinal' => 54],
                'different owner' => ['group_regex' => '^alt\\.binaries\\.boneless$'],
                'owner case' => ['group_regex' => '^Alt\\.binaries\\.erotica$'],
                'owner whitespace' => ['group_regex' => '^alt\\.binaries\\.erotica$ '],
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
            DB::table('collection_regexes')->where('id', 284)->delete();
        } else {
            DB::table('collection_regexes')->where('id', 284)->update($changes);
        }
        Cache::forever('collection_regexes_revision', 'unchanged');
        // An in-flight posting must not turn an ineligible row's no-op into an error.
        $this->ingest(array_slice($this->archiveHeaders('7z'), 0, 1));
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
    public function test_every_in_flight_state_blocks_both_directions(string $direction, int $state, bool $par2First): void
    {
        if ($direction === 'up') {
            $this->installPrevious();
        }
        $headers = $this->archiveHeaders('7z');
        $this->ingest([$headers[$par2First ? 22 : 0]]);
        DB::table('collections')->update(['filecheck' => $state, 'releases_id' => $state === 4 ? 77 : null]);
        $this->assertRefusedUnchanged($direction);
    }

    public function test_release_associated_cbp_blocks_before_and_after_nzb_publication(): void
    {
        foreach (['up', 'down'] as $direction) {
            foreach ([0, 1] as $nzbStatus) {
                $this->resetCohort();
                DB::table('releases')->delete();
                if ($direction === 'up') {
                    $this->installPrevious();
                } else {
                    $this->migration()->up();
                }
                $this->ingest(array_slice($this->archiveHeaders('rar'), 0, 2));
                DB::table('releases')->insert(['id' => 77, 'name' => 'Existing synthetic release',
                    'nzbstatus' => $nzbStatus, 'completion' => 91.67]);
                DB::table('collections')->update(['filecheck' => 4, 'releases_id' => 77]);
                $this->assertRefusedUnchanged($direction);
            }
        }
    }

    public function test_truncated_originals_use_full_binaries_and_never_declare_unknown_empty(): void
    {
        foreach (['up', 'down'] as $direction) {
            $this->resetCohort();
            if ($direction === 'up') {
                $this->installPrevious();
            } else {
                $this->migration()->up();
            }
            $stem = str_repeat('A', 200);
            $full = $stem.' [01/19] - "'.$stem.'.7z.001" yEnc';
            $header = $this->archiveHeaders('7z')[0];
            $header['Subject'] = $full.' (1/2)';
            $this->ingest([$header]);
            $this->assertSame(substr($full, 0, 255), DB::table('collections')->value('subject'));
            $this->assertSame($full, DB::table('binaries')->value('name'));
            $this->assertRefusedUnchanged($direction);
            DB::table('binaries')->update(['name' => substr($full, 0, 255)]);
            $this->assertRefusedUnchanged($direction);
            DB::table('binaries')->delete();
            $this->assertRefusedUnchanged($direction);
            DB::table('collections')->update(['subject' => null]);
            $this->assertRefusedUnchanged($direction);
        }
    }

    public function test_full_binary_can_establish_that_a_truncated_original_is_unrelated(): void
    {
        $this->installPrevious();
        $stem = str_repeat('B', 200);
        $full = $stem.' [01/19] - "'.$stem.'.mkv" yEnc';
        $header = $this->archiveHeaders('7z')[0];
        $header['Subject'] = $full.' (1/2)';
        $this->ingest([$header]);
        $before = $this->snapshot(false);
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot(false));
        $this->assertNotSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
        $this->migration()->down();
        $this->assertSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
    }

    public function test_all_binaries_are_checked_even_when_original_subject_is_unrelated(): void
    {
        $this->installPrevious();
        $headers = $this->archiveHeaders('7z');
        $first = $headers[22];
        $first['Subject'] = str_replace('.par2', '.jpg', $first['Subject']);
        $first['Message-ID'] = '<unrelated@example.invalid>';
        $this->ingest([$first, $headers[24]]);
        $this->assertRefusedUnchanged('up');
    }

    public function test_unrelated_groups_and_rule_owners_do_not_block_activation(): void
    {
        $this->installPrevious();
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.binaries.multimedia.erotica']);
        DB::table('collections')->insert([
            ['groups_id' => 2, 'collection_regexes_id' => 284, 'subject' => 'Sample-B [01/19] - "Sample-B.7z.001" yEnc'],
            ['groups_id' => 1, 'collection_regexes_id' => 283, 'subject' => 'Sample-B [01/19] - "Sample-B.7z.001" yEnc'],
            ['groups_id' => 1, 'collection_regexes_id' => 284, 'subject' => 'Unrelated [01/19] - "Different.mkv" yEnc'],
        ]);
        $before = $this->snapshot(false);
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot(false));
        $this->assertNotSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
    }

    public function test_unrelated_stock_fallback_separators_allow_both_directions(): void
    {
        $this->installPrevious();
        foreach (['-', '_', "\t"] as $index => $separator) {
            $header = $this->archiveHeaders('7z')[0];
            $header['Number'] = 2000 + $index;
            $header['Message-ID'] = '<fallback-'.$index.'@example.invalid>';
            $header['Subject'] = 'Sample-B [01/19] - "Different-'.$index.'.mkv"'.$separator.'yEnc (1/2)';
            $this->ingest([$header]);
        }
        $this->assertSame([284], DB::table('collections')->distinct()->pluck('collection_regexes_id')->all());
        $before = $this->snapshot(false);
        $this->migration()->up();
        $this->assertNotSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
        $this->assertSame($before, $this->snapshot(false));
        $this->migration()->down();
        $this->assertSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
        $this->assertSame($before, $this->snapshot(false));
    }

    public function test_cohort_walk_reaches_beyond_the_first_collection_and_binary_chunks(): void
    {
        $this->installPrevious();
        for ($i = 1; $i <= 201; $i++) {
            DB::table('collections')->insert(['id' => $i, 'groups_id' => 1, 'collection_regexes_id' => 284,
                'subject' => 'Unrelated [01/19] - "Different.mkv" yEnc']);
        }
        for ($i = 1; $i <= 201; $i++) {
            DB::table('binaries')->insert(['id' => $i, 'collections_id' => 201,
                'name' => 'Unrelated [01/19] - "Different.mkv" yEnc']);
        }
        $this->migration()->up();
        $this->migration()->down();
        DB::table('binaries')->where('id', 201)->update(['name' => 'Sample-B [01/19] - "Sample-B.7z.001" yEnc']);
        $this->assertRefusedUnchanged('up');
    }

    public function test_unsafe_mid_post_switch_splits_7z_but_migration_prevents_it(): void
    {
        $corrected = DB::table('collection_regexes')->where('id', 284)->value('regex');
        $headers = $this->archiveHeaders('7z');
        $this->installPrevious();
        $this->ingest(array_slice($headers, 0, 2));
        // Deliberately reproduce the unsafe deployment the migration must prevent.
        DB::table('collection_regexes')->where('id', 284)->update(['regex' => $corrected]);
        Cache::flush();
        $this->ingest(array_slice($headers, 2, 2));
        $this->assertSame(2, DB::table('collections')->count());

        $this->resetCohort();
        $this->installPrevious();
        $this->ingest(array_slice($headers, 0, 2));
        $this->assertRefusedUnchanged('up');
        $this->ingest(array_slice($headers, 2, 2));
        $this->assertSame(1, DB::table('collections')->count());
        $this->resetCohort();
        $this->migration()->up();
        $this->ingest($headers);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(19, DB::table('binaries')->count());
    }

    public function test_stock_ownership_is_checked_again_at_the_write_boundary(): void
    {
        $this->installPrevious();
        Cache::forever('collection_regexes_revision', 'unchanged');
        $interleaved = false;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$interleaved): void {
            if (! $interleaved && str_starts_with($sql, 'update "collection_regexes"')) {
                $interleaved = true;
                DB::table('collection_regexes')->where('id', 284)->update(['regex' => '~local edit~']);
            }
        });
        $this->migration()->up();
        $this->assertTrue($interleaved);
        $this->assertSame('~local edit~', DB::table('collection_regexes')->where('id', 284)->value('regex'));
        $this->assertSame('unchanged', Cache::get('collection_regexes_revision'));
    }

    public function test_a_local_description_is_not_a_stock_ownership_field(): void
    {
        $this->installPrevious();
        DB::table('collection_regexes')->where('id', 284)->update(['description' => 'Local explanation']);
        $this->migration()->up();
        $this->assertNotSame(self::PREVIOUS, DB::table('collection_regexes')->where('id', 284)->value('regex'));
        $this->migration()->down();
        $this->assertSame('Local explanation', DB::table('collection_regexes')->where('id', 284)->value('description'));
    }

    private function assertRefusedUnchanged(string $direction): void
    {
        Cache::forever('collection_regexes_revision', 'unchanged');
        $before = $this->snapshot();
        $error = null;
        try {
            $this->migration()->{$direction}();
        } catch (RuntimeException $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'The actual migration must reject this cohort.');
        $this->assertStringContainsString('drain', $error->getMessage());
        $this->assertStringContainsString('quiesced', $error->getMessage());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('unchanged', Cache::get('collection_regexes_revision'));
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

    private function resetCohort(): void
    {
        foreach (['parts', 'binaries', 'collection_groups', 'collections'] as $table) {
            DB::table($table)->delete();
        }
    }
}
