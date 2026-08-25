<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PredbAttachmentNameStateMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('predb_id')->default(0);
            $table->boolean('isrenamed')->default(false);
            $table->boolean('is_trusted_name')->default(false);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_repairs_only_incomplete_predb_attachment_state_idempotently(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'predb_id' => 10, 'isrenamed' => 0, 'is_trusted_name' => 0],
            ['id' => 2, 'predb_id' => 11, 'isrenamed' => 1, 'is_trusted_name' => 0],
            ['id' => 3, 'predb_id' => 0, 'isrenamed' => 0, 'is_trusted_name' => 0],
            ['id' => 4, 'predb_id' => 12, 'isrenamed' => 0, 'is_trusted_name' => 1],
        ]);

        $migration = require database_path('migrations/2026_08_25_115031_backfill_predb_attachment_name_state.php');
        $migration->up();
        $migration->up();

        $states = DB::table('releases')
            ->orderBy('id')
            ->get(['id', 'isrenamed', 'is_trusted_name'])
            ->mapWithKeys(static fn (object $release): array => [
                (int) $release->id => [(int) $release->isrenamed, (int) $release->is_trusted_name],
            ])
            ->all();

        $this->assertSame([
            1 => [1, 1],
            2 => [1, 0],
            3 => [0, 0],
            4 => [1, 1],
        ], $states);
    }
}
