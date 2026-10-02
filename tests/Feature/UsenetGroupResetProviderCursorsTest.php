<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

class UsenetGroupResetProviderCursorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tables = ProductionTables::fromAuthority();
        $tables->create('usenet_groups', ['id', 'name', 'active', 'backfill_target', 'first_record', 'first_record_postdate',
            'last_record', 'last_record_postdate', 'last_updated']);
        $tables->create('missed_parts', ['id', 'numberid', 'groups_id']);
        $tables->create('usenet_group_provider_cursors', ['usenet_groups_id', 'provider', 'last_record']);
        $tables->create('usenet_group_provider_ingested_ranges', ['usenet_groups_id', 'provider', 'first_record']);

        foreach ([1, 2] as $groupId) {
            DB::table('usenet_groups')->insert(['id' => $groupId, 'name' => 'alt.group'.$groupId, 'active' => 1, 'last_record' => 50]);
            DB::table('usenet_group_provider_cursors')->insert(['usenet_groups_id' => $groupId, 'provider' => 'super', 'last_record' => 300]);
            DB::table('usenet_group_provider_ingested_ranges')->insert(['usenet_groups_id' => $groupId, 'provider' => 'super', 'first_record' => 400]);
        }
    }

    public function test_resetting_a_group_forgets_its_secondary_positions_only(): void
    {
        UsenetGroup::reset(1);

        foreach (['usenet_group_provider_cursors', 'usenet_group_provider_ingested_ranges'] as $table) {
            $this->assertSame([2], DB::table($table)->pluck('usenet_groups_id')->map(fn (mixed $id): int => (int) $id)->all());
        }
    }

    public function test_resetting_every_group_forgets_every_secondary_position(): void
    {
        try {
            UsenetGroup::resetall();
        } catch (QueryException $e) {
            // The CBP truncation that follows is MariaDB-only (FOREIGN_KEY_CHECKS, TRUNCATE).
            $this->assertStringContainsString('FOREIGN_KEY_CHECKS', $e->getMessage());
        }

        $this->assertSame(0, DB::table('usenet_group_provider_cursors')->count());
        $this->assertSame(0, DB::table('usenet_group_provider_ingested_ranges')->count());
    }
}
