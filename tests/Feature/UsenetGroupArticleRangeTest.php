<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsenetGroupArticleRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 14:30:00');
        config(['app.timezone' => 'UTC']);

        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_record');
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'first_record' => 500,
            'first_record_postdate' => '2026-08-10 00:00:00',
            'last_record' => 1_000,
            'last_record_postdate' => '2026-08-16 00:00:00',
            'last_updated' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_backfill_progress_uses_the_php_clock_for_last_updated(): void
    {
        UsenetGroup::recordBackfillProgress(1, 400, (int) strtotime('2026-08-09 08:15:00'));

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(400, $group->first_record);
        $this->assertSame('2026-08-09 08:15:00', $group->first_record_postdate);
        $this->assertSame('2026-08-17 14:30:00', $group->last_updated);
    }

    public function test_article_range_updates_bind_the_php_clock_and_keep_monotonic_boundaries(): void
    {
        $this->assertSame(1, UsenetGroup::advanceLastRecord(1, 1_100, (int) strtotime('2026-08-17 10:00:00')));
        $this->assertSame(0, UsenetGroup::advanceLastRecord(1, 1_050, (int) strtotime('2026-08-17 09:00:00')));

        Carbon::setTestNow('2026-08-17 14:31:00');

        $this->assertSame(1, UsenetGroup::rewindFirstRecord(1, 300, (int) strtotime('2026-08-08 07:00:00')));
        $this->assertSame(0, UsenetGroup::rewindFirstRecord(1, 350, (int) strtotime('2026-08-08 08:00:00')));

        $group = DB::table('usenet_groups')->find(1);

        $this->assertSame(300, $group->first_record);
        $this->assertSame('2026-08-08 07:00:00', $group->first_record_postdate);
        $this->assertSame(1_100, $group->last_record);
        $this->assertSame('2026-08-17 10:00:00', $group->last_record_postdate);
        $this->assertSame('2026-08-17 14:31:00', $group->last_updated);
    }
}
