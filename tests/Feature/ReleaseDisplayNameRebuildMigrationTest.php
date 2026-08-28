<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReleaseDisplayNameRebuildMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname');
            $table->string('display_name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    public function test_migration_rebuilds_stale_display_names_with_the_unwrap_rules(): void
    {
        DB::table('releases')->insert([
            [
                'id' => 1,
                'searchname' => '- "Estella.Bathory.720p.SpankBang.com.mp4"',
                'display_name' => '- "Estella Bathory 720p SpankBang com mp4"',
            ],
            [
                'id' => 2,
                'searchname' => '[10/88] - "Show.S01E02.1080p.mkv" yEnc',
                'display_name' => '[10/88] - "Show S01E02 1080p mkv" yEnc',
            ],
            [
                'id' => 3,
                'searchname' => 'Already.Clean.Name.1080p.mp4',
                'display_name' => 'Already Clean Name 1080p MP4',
            ],
            [
                'id' => 4,
                'searchname' => 'Never.Backfilled.mkv',
                'display_name' => null,
            ],
        ]);

        $migration = require database_path('migrations/2026_08_28_130000_rebuild_release_display_names.php');
        $migration->up();

        $this->assertSame(
            [
                'Estella Bathory 720p SpankBang com MP4',
                'Show S01E02 1080p MKV',
                'Already Clean Name 1080p MP4',
                'Never Backfilled MKV',
            ],
            DB::table('releases')->orderBy('id')->pluck('display_name')->all(),
        );
    }
}
