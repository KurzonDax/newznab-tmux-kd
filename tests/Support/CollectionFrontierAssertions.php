<?php

namespace Tests\Support;

use App\Services\ReleaseProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

trait CollectionFrontierAssertions
{
    /** @param array<string, mixed> $group */
    #[DataProvider('frontierCases')]
    public function test_collection_lifecycle_follows_ingested_posting_time(
        ?string $head,
        ?string $tail,
        array $group,
        ?int $expectedStatus,
        int $declaredFiles = 5,
        int $wallHours = 6,
    ): void {
        DB::table('usenet_groups')->where('id', 1)->update($group);
        $old = DB::getDriverName() === 'sqlite'
            ? DB::raw("datetime(CURRENT_TIMESTAMP, '-{$wallHours} hours')")
            : DB::raw("DATE_SUB(NOW(), INTERVAL {$wallHours} HOUR)");
        DB::table('collections')->insert([
            'id' => 901, 'subject' => 'Frontier.Clock', 'fromname' => 'poster@example.test',
            'collectionhash' => hash('sha1', 'frontier-clock', true), 'groups_id' => 1,
            'dateadded' => $old, 'added' => $old, 'last_seen_at' => $old,
            'last_seen_head_postdate' => $head, 'last_seen_tail_postdate' => $tail,
            'totalfiles' => $declaredFiles, 'declaredfiles' => $declaredFiles, 'filecheck' => 0,
        ]);
        $binary = ['id' => 901, 'name' => 'Frontier.Clock.rar', 'collections_id' => 901, 'totalparts' => 1, 'partcheck' => 1];
        if (Schema::hasColumn('binaries', 'binaryhash')) {
            $binary['binaryhash'] = hash('md5', 'frontier-clock', true);
        }
        DB::table('binaries')->insert($binary);
        DB::table('parts')->insert([
            'binaries_id' => 901, 'messageid' => '<frontier@example.test>',
            'number' => 901, 'partnumber' => 1, 'size' => 10,
        ]);

        app(ReleaseProcessingService::class)->setEchoCLI(false)->processIncompleteCollections(1);

        if ($expectedStatus === null) {
            $this->assertDatabaseMissing('collections', ['id' => 901]);
            $this->assertDatabaseMissing('binaries', ['id' => 901]);
            $this->assertDatabaseMissing('parts', ['binaries_id' => 901]);
        } else {
            $this->assertDatabaseHas('collections', [
                'id' => 901, 'filecheck' => $expectedStatus, 'declaredfiles' => $declaredFiles,
                'totalfiles' => $expectedStatus === 2 ? 1 : $declaredFiles,
            ]);
        }
    }

    /** @return iterable<string, array<int, mixed>> */
    public static function frontierCases(): iterable
    {
        $stamp = '2026-08-01 12:00:00';
        yield 'head frozen during downtime' => [$stamp, null, ['last_record_postdate' => $stamp], 0];
        yield 'head one minute short' => [$stamp, null, ['last_record_postdate' => '2026-08-01 13:59:00'], 0];
        yield 'head at boundary' => [$stamp, null, ['last_record_postdate' => '2026-08-01 14:00:00'], 2];
        yield 'straggler restarts head wait' => ['2026-08-01 13:00:00', null, ['last_record_postdate' => '2026-08-01 14:00:00'], 0];
        yield 'tail one minute short' => [null, $stamp, ['first_record_postdate' => '2026-08-01 10:01:00'], 0];
        yield 'tail at boundary' => [null, $stamp, ['first_record_postdate' => '2026-08-01 10:00:00'], 2];
        yield 'tail settled' => [null, $stamp, ['backfill_settled_at' => $stamp], 2];
        yield 'tail disabled' => [null, $stamp, ['backfill' => 0], 2];
        yield 'settled tail waits wall delay' => [null, $stamp, ['backfill_settled_at' => $stamp], 0, 5, 1];
        yield 'both need head' => [$stamp, $stamp, ['last_record_postdate' => $stamp, 'first_record_postdate' => '2026-08-01 10:00:00'], 0];
        yield 'both need tail' => [$stamp, $stamp, ['last_record_postdate' => '2026-08-01 14:00:00', 'first_record_postdate' => $stamp], 0];
        yield 'both quiet' => [$stamp, $stamp, ['last_record_postdate' => '2026-08-01 14:00:00', 'first_record_postdate' => '2026-08-01 10:00:00'], 2];
        yield 'disabled head promotes before timeout' => [$stamp, null, ['active' => 0], 2];
        yield 'disabled head waits wall delay' => [$stamp, null, ['active' => 0], 0, 5, 1];
        yield 'legacy uses wall clock' => [null, null, ['last_record_postdate' => $stamp], 2];
        yield 'complete evidence ignores frozen frontier' => [$stamp, null, ['last_record_postdate' => $stamp], 2, 1];
        yield 'stuck frozen for days' => [$stamp, null, ['last_record_postdate' => $stamp], 0, 5, 100];
        yield 'stuck head at timeout boundary' => [$stamp, null, ['last_record_postdate' => '2026-08-03 12:00:00'], null];
        yield 'stuck tail at timeout boundary' => [null, $stamp, ['first_record_postdate' => '2026-07-30 12:00:00'], null];
    }
}
