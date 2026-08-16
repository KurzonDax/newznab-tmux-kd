<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NameTrustMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->boolean('isrenamed')->default(false);
            $table->integer('proc_nfo')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('proc_uid')->default(0);
            $table->integer('proc_srr')->default(0);
            $table->integer('proc_hash16k')->default(0);
            $table->integer('proc_crc32')->default(0);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_does_not_infer_name_provenance_from_ambiguous_processing_flags(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'isrenamed' => 1, 'proc_nfo' => 1, 'proc_par2' => 0, 'proc_uid' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 0],
            ['id' => 2, 'isrenamed' => 1, 'proc_nfo' => 0, 'proc_par2' => 0, 'proc_uid' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 1],
            ['id' => 3, 'isrenamed' => 1, 'proc_nfo' => 0, 'proc_par2' => 0, 'proc_uid' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 0],
            ['id' => 4, 'isrenamed' => 0, 'proc_nfo' => 0, 'proc_par2' => 1, 'proc_uid' => 0, 'proc_srr' => 0, 'proc_hash16k' => 0, 'proc_crc32' => 0],
        ]);

        $migration = require database_path('migrations/2026_08_16_142055_add_name_trust_to_releases_table.php');
        $migration->up();

        $trustByRelease = DB::table('releases')->orderBy('id')->pluck('is_trusted_name', 'id')->map(
            static fn (mixed $trusted): int => (int) $trusted
        )->all();

        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0], $trustByRelease);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('releases', 'is_trusted_name'));
    }
}
