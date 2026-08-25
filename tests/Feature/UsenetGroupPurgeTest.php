<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\UsenetGroup;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsenetGroupPurgeTest extends TestCase
{
    private string $nzbDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        $this->nzbDirectory = $this->makeTempDirectory('group-purge-nzbs').DIRECTORY_SEPARATOR;
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory,
            'nntmux_settings.covers_path' => $this->makeTempDirectory('group-purge-covers'),
        ]);

        $this->createTables();
        DB::table('settings')->insert(['name' => 'nzbsplitlevel', 'value' => '1']);
        DB::table('usenet_groups')->insert([
            $this->groupRow(1, 'alt.binaries.purged'),
            $this->groupRow(2, 'alt.binaries.surviving'),
        ]);
    }

    public function test_single_group_purge_is_a_clean_slate_and_spares_shared_releases(): void
    {
        $this->insertCollectionTree(100, 1);
        $this->insertCollectionTree(200, 2);
        DB::table('missed_parts')->insert([
            ['id' => 1, 'numberid' => 1001, 'groups_id' => 1],
            ['id' => 2, 'numberid' => 2001, 'groups_id' => 2],
        ]);

        DB::table('releases')->insert([
            ['id' => 10, 'guid' => 'sole-release-guid', 'groups_id' => 1],
            ['id' => 20, 'guid' => 'shared-release-guid', 'groups_id' => 1],
            ['id' => 30, 'guid' => 'surviving-release-guid', 'groups_id' => 2],
        ]);
        DB::table('releases_groups')->insert([
            ['releases_id' => 10, 'groups_id' => 1],
            ['releases_id' => 20, 'groups_id' => 1],
            ['releases_id' => 20, 'groups_id' => 2],
            ['releases_id' => 30, 'groups_id' => 2],
        ]);

        $nzb = app(NzbService::class);
        $soleNzbPath = $nzb->getNzbPath('sole-release-guid', createIfNotExist: true);
        $sharedNzbPath = $nzb->getNzbPath('shared-release-guid', createIfNotExist: true);
        File::put($soleNzbPath, 'sole');
        File::put($sharedNzbPath, 'shared');

        Search::shouldReceive('deleteRelease')->once()->with(10);

        UsenetGroup::purge(1);

        $this->assertDatabaseMissing('collections', ['id' => 100]);
        $this->assertDatabaseMissing('binaries', ['collections_id' => 100]);
        $this->assertDatabaseMissing('parts', ['binaries_id' => 1000]);
        $this->assertDatabaseMissing('missed_parts', ['groups_id' => 1]);

        $this->assertDatabaseHas('collections', ['id' => 200]);
        $this->assertDatabaseHas('binaries', ['collections_id' => 200]);
        $this->assertDatabaseHas('parts', ['binaries_id' => 2000]);
        $this->assertDatabaseHas('missed_parts', ['groups_id' => 2]);

        $this->assertDatabaseMissing('releases', ['id' => 10]);
        $this->assertDatabaseHas('releases', ['id' => 20]);
        $this->assertDatabaseHas('releases', ['id' => 30]);
        $this->assertFileDoesNotExist($soleNzbPath);
        $this->assertFileExists($sharedNzbPath);

        $group = DB::table('usenet_groups')->where('id', 1)->first();
        $this->assertNotNull($group);
        $this->assertSame(0, (int) $group->active);
        $this->assertSame(0, (int) $group->first_record);
        $this->assertSame(0, (int) $group->last_record);

        DB::table('usenet_groups')->where('id', 1)->update(['active' => 1]);
        $this->insertCollectionTree(101, 1);

        $this->assertSame([101], DB::table('collections')->where('groups_id', 1)->pluck('id')->all());
        $this->assertSame([1010], DB::table('binaries')->where('collections_id', 101)->pluck('id')->all());
    }

    /** @return array<string, int|string|null> */
    private function groupRow(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'backfill_target' => 30,
            'first_record' => 100,
            'first_record_postdate' => now()->format('Y-m-d H:i:s'),
            'last_record' => 200,
            'last_record_postdate' => now()->format('Y-m-d H:i:s'),
            'last_updated' => now()->format('Y-m-d H:i:s'),
            'active' => 1,
        ];
    }

    private function insertCollectionTree(int $collectionId, int $groupId): void
    {
        DB::table('collections')->insert([
            'id' => $collectionId,
            'groups_id' => $groupId,
            'releases_id' => null,
        ]);
        DB::table('binaries')->insert([
            'id' => $collectionId * 10,
            'collections_id' => $collectionId,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $collectionId * 10,
            'partnumber' => 1,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('backfill_target')->default(1);
            $table->unsignedBigInteger('first_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
            $table->boolean('active')->default(false);
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('releases_id')->nullable();
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('collections_id');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('binaries_id');
            $table->unsignedInteger('partnumber');
        });
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->unsignedInteger('groups_id');
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
        });
    }
}
