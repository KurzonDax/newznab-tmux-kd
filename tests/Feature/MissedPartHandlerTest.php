<?php

namespace Tests\Feature;

use App\Services\Binaries\MissedPartHandler;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MissedPartHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');
    }

    public function test_add_missing_parts_inserts_and_increments_duplicates(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);

        $handler->addMissingParts([100, 101, 102], 7);
        $handler->addMissingParts([101, 103], 7);
        $handler->addMissingParts([101], 8);

        $this->assertSame(4, DB::table('missed_parts')->where('groups_id', 7)->count());
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 7, 'numberid' => 100])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 7, 'numberid' => 101])->value('attempts'));
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 8, 'numberid' => 101])->value('attempts'));
    }

    /**
     * The MySQL path cannot run against the SQLite test connection, so pin the statement
     * it builds: the entry baseline and the re-detection bump have to match the SQLite
     * path exactly, or the two drivers give a group a different number of repair tries.
     */
    public function test_the_mysql_insert_path_queues_parts_with_the_same_zero_baseline(): void
    {
        $statement = null;
        $parameters = null;

        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('insert')->once()->andReturnUsing(
            function (string $sql, array $bindings) use (&$statement, &$parameters): bool {
                $statement = $sql;
                $parameters = $bindings;

                return true;
            }
        );

        (new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3))->addMissingParts([100, 101], 7);

        $this->assertStringContainsString('VALUES (?, ?, 0),(?, ?, 0)', $statement);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE attempts = attempts + 1', $statement);
        $this->assertSame([100, 7, 101, 7], $parameters);
    }

    /**
     * `attempts` counts completed repair attempts, so a stored maximum of N has to buy
     * exactly N of them. Entering the queue at 1 used to spend a try nobody made.
     */
    public function test_a_part_that_keeps_failing_is_offered_for_repair_exactly_the_configured_number_of_times(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        $handler->addMissingParts([100], 7);

        $this->assertSame(3, $this->countFailingRepairAttempts($handler, 7));
        $this->assertFalse(DB::table('missed_parts')->where('groups_id', 7)->exists());
    }

    /**
     * A maximum of 1 used to record the part and then discard it on the next cleanup pass
     * without ever offering it, which made 2 the smallest value that repaired anything.
     */
    public function test_a_maximum_of_one_still_buys_a_single_repair_attempt(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 1);
        $handler->addMissingParts([100], 7);

        $this->assertSame(1, $this->countFailingRepairAttempts($handler, 7));
        $this->assertFalse(DB::table('missed_parts')->where('groups_id', 7)->exists());
    }

    /**
     * Run the repair cycle -- offer, fail, age, clean up -- until the group's queue is
     * empty, and report how many times the part was actually offered for repair.
     */
    private function countFailingRepairAttempts(MissedPartHandler $handler, int $groupId): int
    {
        $offers = 0;

        for ($pass = 0; $pass < 10; $pass++) {
            $parts = $handler->getMissingParts($groupId);

            if ($parts !== []) {
                $offers++;
                $handler->incrementAttempts($groupId, (int) $parts[count($parts) - 1]->numberid);
            }

            $handler->cleanupExhaustedParts($groupId);

            if (! DB::table('missed_parts')->where('groups_id', $groupId)->exists()) {
                return $offers;
            }
        }

        $this->fail('the missed part never left the repair queue');
    }

    public function test_get_missing_parts_applies_attempt_limit_order_and_repair_limit(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 2, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 300, 'groups_id' => 9, 'attempts' => 1],
            ['numberid' => 100, 'groups_id' => 9, 'attempts' => 1],
            ['numberid' => 200, 'groups_id' => 9, 'attempts' => 3],
            ['numberid' => 50, 'groups_id' => 8, 'attempts' => 1],
        ]);

        $parts = $handler->getMissingParts(9);

        $this->assertCount(2, $parts);
        $this->assertSame([100, 300], array_map(static fn (object $part): int => (int) $part->numberid, $parts));
    }

    public function test_remove_increment_count_and_cleanup_paths(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 10, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 20, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 30, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 20, 'groups_id' => 2, 'attempts' => 1],
        ]);

        $handler->removeRepairedParts([20, 999], 1);
        $this->assertFalse(DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 20])->exists());
        $this->assertTrue(DB::table('missed_parts')->where(['groups_id' => 2, 'numberid' => 20])->exists());

        $handler->incrementAttempts(1, 30);
        $this->assertSame(2, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));
        $this->assertSame(3, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 30])->value('attempts'));
        $this->assertSame(2, $handler->getCount(1, 30));

        $handler->cleanupExhaustedParts(1);
        $this->assertSame([10], DB::table('missed_parts')->where('groups_id', 1)->pluck('numberid')->all());
    }

    public function test_increment_range_attempts_handles_single_and_multi_article_ranges(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 10, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 11, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 12, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 12, 'groups_id' => 2, 'attempts' => 0],
        ]);

        $handler->incrementRangeAttempts(1, 10, 10);
        $handler->incrementRangeAttempts(1, 11, 12);

        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 11])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 12])->value('attempts'));
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 2, 'numberid' => 12])->value('attempts'));
    }
}
