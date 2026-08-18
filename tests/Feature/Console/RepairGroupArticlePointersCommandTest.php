<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One-time repair for groups whose last_record was corrupted by a shifted overview
 * format (issue #116).
 */
class RepairGroupArticlePointersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(1);
            $table->unsignedBigInteger('first_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });

        Schema::dropIfExists('collections');
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id');
            $table->dateTime('date')->nullable();
        });

        Schema::dropIfExists('binaries');
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
        });

        Schema::dropIfExists('parts');
        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('binaries_id');
            $table->unsignedBigInteger('number');
        });

        Schema::dropIfExists('missed_parts');
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedTinyInteger('attempts')->default(0);
        });
    }

    protected function tearDown(): void
    {
        foreach (['missed_parts', 'parts', 'binaries', 'collections', 'usenet_groups'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_a_dry_run_reports_broken_groups_without_changing_anything(): void
    {
        $this->createGroup(1, 'alt.binaries.boneless', firstRecord: 1_000, lastRecord: 2);
        $this->createStoredArticle(1, 151_801_130_293, '2026-08-11 13:00:00');

        $this->artisan('groups:repair-article-pointers')
            ->expectsOutputToContain('alt.binaries.boneless')
            ->expectsOutputToContain('--execute')
            ->assertSuccessful();

        $group = DB::table('usenet_groups')->find(1);
        $this->assertSame(2, (int) $group->last_record, 'A dry run must not write.');
    }

    public function test_it_resumes_a_broken_group_from_the_newest_stored_article(): void
    {
        $this->createGroup(1, 'alt.binaries.boneless', firstRecord: 1_000, lastRecord: 2);
        $this->createStoredArticle(1, 151_801_130_000, '2026-08-10 09:00:00');
        $this->createStoredArticle(1, 151_801_130_293, '2026-08-11 13:00:00');

        $this->artisan('groups:repair-article-pointers --execute')->assertSuccessful();

        $group = DB::table('usenet_groups')->find(1);
        $this->assertSame(151_801_130_293, (int) $group->last_record);
        $this->assertSame('2026-08-11 13:00:00', $group->last_record_postdate);
        $this->assertSame(1_000, (int) $group->first_record, 'first_record stays put when articles are stored.');
    }

    public function test_it_detects_a_pointer_coerced_to_the_unsigned_bigint_ceiling(): void
    {
        $this->createGroup(1, 'alt.binaries.moovee', firstRecord: 1_000, lastRecord: '18446744073709551615');
        $this->createStoredArticle(1, 3_694_000_000, '2026-08-11 20:00:00');

        $this->artisan('groups:repair-article-pointers --execute')->assertSuccessful();

        $this->assertSame(3_694_000_000, (int) DB::table('usenet_groups')->find(1)->last_record);
    }

    public function test_a_group_with_no_stored_articles_is_re_anchored_as_new(): void
    {
        $this->createGroup(1, 'alt.binaries.town.xxx', firstRecord: 5_000, lastRecord: 9);

        $this->artisan('groups:repair-article-pointers --execute')->assertSuccessful();

        $group = DB::table('usenet_groups')->find(1);
        $this->assertSame(0, (int) $group->last_record);
        $this->assertSame(0, (int) $group->first_record);
        $this->assertNull($group->last_record_postdate);
        $this->assertNull($group->first_record_postdate);
    }

    public function test_healthy_and_inactive_groups_are_left_alone(): void
    {
        $this->createGroup(1, 'alt.binaries.healthy', firstRecord: 1_000, lastRecord: 5_000);
        $this->createGroup(2, 'alt.binaries.disabled', firstRecord: 1_000, lastRecord: 3, active: false);

        $this->artisan('groups:repair-article-pointers --execute')
            ->expectsOutputToContain('No groups')
            ->assertSuccessful();

        $this->assertSame(5_000, (int) DB::table('usenet_groups')->find(1)->last_record);
        $this->assertSame(3, (int) DB::table('usenet_groups')->find(2)->last_record);
    }

    public function test_inactive_groups_can_be_opted_in(): void
    {
        $this->createGroup(2, 'alt.binaries.disabled', firstRecord: 1_000, lastRecord: 3, active: false);

        $this->artisan('groups:repair-article-pointers --execute --include-inactive')->assertSuccessful();

        $this->assertSame(0, (int) DB::table('usenet_groups')->find(2)->last_record);
    }

    public function test_it_can_be_limited_to_one_group(): void
    {
        $this->createGroup(1, 'alt.binaries.boneless', firstRecord: 1_000, lastRecord: 2);
        $this->createGroup(2, 'alt.binaries.etc', firstRecord: 1_000, lastRecord: 2);

        $this->artisan('groups:repair-article-pointers --execute --group=alt.binaries.etc')->assertSuccessful();

        $this->assertSame(2, (int) DB::table('usenet_groups')->find(1)->last_record);
        $this->assertSame(0, (int) DB::table('usenet_groups')->find(2)->last_record);
    }

    public function test_missed_parts_are_only_purged_for_repaired_groups_when_asked(): void
    {
        $this->createGroup(1, 'alt.binaries.town.xxx', firstRecord: 5_000, lastRecord: 9);
        $this->createGroup(2, 'alt.binaries.healthy', firstRecord: 1_000, lastRecord: 5_000);
        DB::table('missed_parts')->insert([
            ['numberid' => 11, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 12, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 13, 'groups_id' => 2, 'attempts' => 1],
        ]);

        $this->artisan('groups:repair-article-pointers --execute --purge-missed-parts')->assertSuccessful();

        $this->assertSame([13], DB::table('missed_parts')->pluck('numberid')->map(fn ($n): int => (int) $n)->all());
    }

    public function test_missed_parts_survive_a_repair_that_did_not_ask_for_a_purge(): void
    {
        $this->createGroup(1, 'alt.binaries.town.xxx', firstRecord: 5_000, lastRecord: 9);
        DB::table('missed_parts')->insert([['numberid' => 11, 'groups_id' => 1, 'attempts' => 1]]);

        $this->artisan('groups:repair-article-pointers --execute')->assertSuccessful();

        $this->assertSame(1, DB::table('missed_parts')->count());
    }

    private function createGroup(int $id, string $name, int $firstRecord, int|string $lastRecord, bool $active = true): void
    {
        DB::table('usenet_groups')->insert([
            'id' => $id,
            'name' => $name,
            'active' => $active,
            'first_record' => $firstRecord,
            'first_record_postdate' => '2026-08-01 00:00:00',
            'last_record' => $lastRecord,
            'last_record_postdate' => '2026-08-11 13:00:00',
            'last_updated' => '2026-08-18 00:00:00',
        ]);
    }

    private function createStoredArticle(int $groupId, int $number, string $date): void
    {
        $collectionId = DB::table('collections')->insertGetId(['groups_id' => $groupId, 'date' => $date]);
        $binaryId = DB::table('binaries')->insertGetId(['collections_id' => $collectionId]);
        DB::table('parts')->insert(['binaries_id' => $binaryId, 'number' => $number]);
    }
}
