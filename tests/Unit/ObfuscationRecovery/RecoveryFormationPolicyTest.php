<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryFormationPolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class RecoveryFormationPolicyTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private const int CATEGORY = 7010;

    private const int MINIMUM = 78643200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'minfilestoformrelease', 'minsizetoformrelease']);
        $tables->create('categories', ['id', 'status', 'minsizetoformrelease']);
        DB::table('usenet_groups')->insert(['id' => 1, 'minfilestoformrelease' => 0, 'minsizetoformrelease' => 0]);
        DB::table('categories')->insert(['id' => self::CATEGORY, 'status' => 1, 'minsizetoformrelease' => 0]);
        DB::table('settings')->insert([
            ['name' => 'minsizetoformrelease', 'value' => (string) self::MINIMUM],
            ['name' => 'maxsizetoformrelease', 'value' => (string) (self::MINIMUM * 4)],
            ['name' => 'minfilestoformrelease', 'value' => '0'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_collection_at_zero_with_enough_planned_bytes_is_not_blocked(): void
    {
        $this->assertNull((new RecoveryFormationPolicy)->blockedReason($this->collection(0), self::CATEGORY, self::MINIMUM));
    }

    public function test_planned_bytes_over_the_maximum_are_blocked(): void
    {
        $this->assertSame('maximum_size', (new RecoveryFormationPolicy)->blockedReason($this->collection(0), self::CATEGORY, self::MINIMUM * 4 + 1));
    }

    public function test_planned_bytes_below_the_category_minimum_are_blocked(): void
    {
        DB::table('categories')->update(['minsizetoformrelease' => self::MINIMUM * 2]);

        $this->assertSame('category_minimum_size', (new RecoveryFormationPolicy)->blockedReason($this->collection(0), self::CATEGORY, self::MINIMUM));
    }

    public function test_a_stored_size_above_the_plan_still_decides(): void
    {
        $this->assertNull((new RecoveryFormationPolicy)->blockedReason($this->collection(self::MINIMUM), self::CATEGORY, 1));
        $this->assertSame('maximum_size', (new RecoveryFormationPolicy)->blockedReason($this->collection(self::MINIMUM * 4 + 1), self::CATEGORY, self::MINIMUM));
    }

    public function test_omitting_the_planned_bytes_behaves_as_before(): void
    {
        $policy = new RecoveryFormationPolicy;

        $this->assertSame('minimum_size', $policy->blockedReason($this->collection(0), self::CATEGORY));
        $this->assertNull($policy->blockedReason($this->collection(self::MINIMUM), self::CATEGORY));
        $this->assertSame('maximum_size', $policy->blockedReason($this->collection(self::MINIMUM * 4 + 1), self::CATEGORY));
    }

    private function collection(int $filesize): object
    {
        return (object) ['groups_id' => 1, 'totalfiles' => 2, 'filesize' => $filesize];
    }
}
