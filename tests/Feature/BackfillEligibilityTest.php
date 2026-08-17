<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackfillEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-17 12:00:00');

        Schema::dropIfExists('short_groups');
        Schema::dropIfExists('usenet_groups');

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record')->nullable();
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedInteger('backfill_target')->default(1);
            $table->boolean('backfill')->default(false);
        });

        Schema::create('short_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
        });

        $this->setSetting('backfill_days', '1');
        $this->setSetting('backfill_order', '2');
        $this->setSetting('safebackfilldate', '2026-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('short_groups');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_days_per_group_mode_only_returns_groups_that_still_have_work_before_their_target(): void
    {
        $this->addGroup('eligible', firstRecord: 1_000, firstRecordPostdate: '2026-08-12', backfillTarget: 30, serverFirst: 100);
        $this->addGroup('disabled', firstRecord: 1_000, firstRecordPostdate: '2026-08-12', backfillTarget: 30, serverFirst: 100, backfill: false);
        $this->addGroup('missing-first-record', firstRecord: null, firstRecordPostdate: '2026-08-12', backfillTarget: 30, serverFirst: 100);
        $this->addGroup('missing-postdate', firstRecord: 1_000, firstRecordPostdate: null, backfillTarget: 30, serverFirst: 100);
        $this->addGroup('at-target', firstRecord: 1_000, firstRecordPostdate: '2026-07-18', backfillTarget: 30, serverFirst: 100);
        $this->addGroup('no-remaining-articles', firstRecord: 100, firstRecordPostdate: '2026-08-12', backfillTarget: 30, serverFirst: 100);

        $groups = (new BackfillService)->eligibleGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('eligible', $groups[0]->name);
        $this->assertSame(900, $groups[0]->remaining);
        $this->assertSame('2026-07-18', $groups[0]->targetDate);
    }

    public function test_safe_date_mode_uses_the_configured_calendar_date_as_the_target(): void
    {
        $this->setSetting('backfill_days', '2');
        $this->addGroup('eligible', firstRecord: 1_000, firstRecordPostdate: '2026-07-02', backfillTarget: 1, serverFirst: 100);
        $this->addGroup('at-target', firstRecord: 1_000, firstRecordPostdate: '2026-07-01', backfillTarget: 365, serverFirst: 100);

        $groups = (new BackfillService)->eligibleGroups();

        $this->assertCount(1, $groups);
        $this->assertSame('eligible', $groups[0]->name);
        $this->assertSame('2026-07-01', $groups[0]->targetDate);
    }

    public function test_group_work_returns_header_status_after_the_group_reaches_its_target(): void
    {
        $this->addGroup('at-target', firstRecord: 1_000, firstRecordPostdate: '2026-07-18', backfillTarget: 30, serverFirst: 100);

        $service = new BackfillService;

        $this->assertSame([], $service->eligibleGroups());

        $status = $service->groupWork('at-target');

        $this->assertNotNull($status);
        $this->assertSame(900, $status->remaining);
        $this->assertSame('2026-07-18', $status->targetDate);
    }

    #[DataProvider('backfillOrderProvider')]
    public function test_eligible_groups_respect_each_backfill_order(int $order, array $expectedNames): void
    {
        $this->setSetting('backfill_order', (string) $order);
        $this->addGroup('alpha', firstRecord: 1_000, firstRecordPostdate: '2026-08-01', backfillTarget: 60, serverFirst: 100, serverLast: 200);
        $this->addGroup('beta', firstRecord: 1_000, firstRecordPostdate: '2026-08-15', backfillTarget: 60, serverFirst: 100, serverLast: 300);
        $this->addGroup('gamma', firstRecord: 1_000, firstRecordPostdate: '2026-08-10', backfillTarget: 60, serverFirst: 100, serverLast: 100);

        $groups = (new BackfillService)->eligibleGroups();

        $this->assertSame($expectedNames, array_map(static fn ($group): string => $group->name, $groups));
    }

    public static function backfillOrderProvider(): iterable
    {
        yield 'newest first' => [1, ['beta', 'gamma', 'alpha']];
        yield 'oldest first' => [2, ['alpha', 'gamma', 'beta']];
        yield 'alphabetical' => [3, ['alpha', 'beta', 'gamma']];
        yield 'reverse alphabetical' => [4, ['gamma', 'beta', 'alpha']];
        yield 'most posts' => [5, ['beta', 'alpha', 'gamma']];
        yield 'fewest posts' => [6, ['gamma', 'alpha', 'beta']];
    }

    private function addGroup(
        string $name,
        ?int $firstRecord,
        ?string $firstRecordPostdate,
        int $backfillTarget,
        int $serverFirst,
        int $serverLast = 1_000,
        bool $backfill = true,
    ): void {
        DB::table('usenet_groups')->insert([
            'name' => $name,
            'first_record' => $firstRecord,
            'first_record_postdate' => $firstRecordPostdate,
            'backfill_target' => $backfillTarget,
            'backfill' => $backfill,
        ]);

        DB::table('short_groups')->insert([
            'name' => $name,
            'first_record' => $serverFirst,
            'last_record' => $serverLast,
        ]);
    }

    private function setSetting(string $name, string $value): void
    {
        DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
    }
}
