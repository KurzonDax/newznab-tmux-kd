<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A backfill pass asks for a number of articles. A request of 0 -- stored in the settings
 * row, or produced by casting a missing row -- used to survive normalization because it is
 * numeric, which made the article-mode target land on the group's own first record. The
 * achievability check then read that as "history exhausted" and, with auto-disable on,
 * switched backfill off for every group the pass touched.
 */
class BackfillArticleQuantityTest extends TestCase
{
    private const GROUP_FIRST_RECORD = 100_000;

    private const SERVER_FIRST_RECORD = 1;

    /** @var list<array{first: int, last: int}> */
    private array $scanWindows = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('usenet_groups');

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record')->nullable();
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedInteger('backfill_target')->default(30);
            $table->boolean('backfill')->default(true);
            $table->dateTime('last_updated')->nullable();
        });

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        DB::table('settings')->where('name', 'backfill_qty')->delete();

        $this->scanWindows = [];
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: int|string|null}>
     */
    public static function unusableArticleRequestProvider(): array
    {
        return [
            'stored zero, or a missing row the tmux runner cast to zero' => [0],
            'stored zero as text' => ['0'],
            'negative' => [-5],
            'negative as text' => ['-5'],
            'missing row the console command forwarded raw' => [null],
            'non numeric' => ['plenty'],
        ];
    }

    #[DataProvider('unusableArticleRequestProvider')]
    public function test_an_unusable_article_request_backfills_the_default_article_count(int|string|null $articles): void
    {
        $this->addGroup();

        $this->makeService()->backfillGroup($this->groupRow(), 0, $articles);

        $this->assertSame(
            [['first' => self::GROUP_FIRST_RECORD - 20_000, 'last' => self::GROUP_FIRST_RECORD - 1]],
            $this->scanWindows,
        );
    }

    public function test_the_console_commands_missing_settings_row_reaches_the_service_as_a_default_request(): void
    {
        $this->addGroup();

        // `backfill:group <group> 2` with no quantity argument reads backfill_qty; with no row
        // to read, settingValue() reports null and the command forwards it untouched.
        $quantity = Settings::settingValue('backfill_qty');
        $this->assertNull($quantity);

        $this->makeService()->backfillAllGroups('alt.test', $quantity);

        $this->assertSame(
            [['first' => self::GROUP_FIRST_RECORD - 20_000, 'last' => self::GROUP_FIRST_RECORD - 1]],
            $this->scanWindows,
        );
    }

    public function test_a_zero_article_request_never_disables_a_group_that_still_has_history(): void
    {
        $this->addGroup();

        $this->makeService(disableBackfillGroup: true)->backfillGroup($this->groupRow(), 0, 0);

        $this->assertSame(1, (int) DB::table('usenet_groups')->where('name', 'alt.test')->value('backfill'));
        $this->assertCount(1, $this->scanWindows);
    }

    public function test_a_stored_positive_article_request_passes_through_unchanged(): void
    {
        $this->addGroup();

        $this->makeService()->backfillGroup($this->groupRow(), 0, '500');

        $this->assertSame(
            [['first' => self::GROUP_FIRST_RECORD - 500, 'last' => self::GROUP_FIRST_RECORD - 1]],
            $this->scanWindows,
        );
    }

    public function test_a_date_based_request_still_resolves_its_target_from_the_day_count(): void
    {
        $this->addGroup();

        $this->makeService()->backfillGroup($this->groupRow(), 0, '');

        $this->assertSame(
            [['first' => 90_000, 'last' => self::GROUP_FIRST_RECORD - 1]],
            $this->scanWindows,
        );
    }

    public function test_genuine_exhaustion_still_disables_the_group_when_auto_disable_is_on(): void
    {
        $this->addGroup(firstRecord: self::SERVER_FIRST_RECORD);

        $this->makeService(disableBackfillGroup: true)
            ->backfillGroup(['id' => 1, 'name' => 'alt.test', 'first_record' => self::SERVER_FIRST_RECORD, 'backfill_target' => 30], 0, 0);

        $this->assertSame(0, (int) DB::table('usenet_groups')->where('name', 'alt.test')->value('backfill'));
        $this->assertSame([], $this->scanWindows);
    }

    public function test_genuine_exhaustion_leaves_the_group_alone_when_auto_disable_is_off(): void
    {
        $this->addGroup(firstRecord: self::SERVER_FIRST_RECORD);

        $this->makeService()
            ->backfillGroup(['id' => 1, 'name' => 'alt.test', 'first_record' => self::SERVER_FIRST_RECORD, 'backfill_target' => 30], 0, 0);

        $this->assertSame(1, (int) DB::table('usenet_groups')->where('name', 'alt.test')->value('backfill'));
        $this->assertSame([], $this->scanWindows);
    }

    private function makeService(bool $disableBackfillGroup = false): BackfillService
    {
        return new BackfillService(
            config: new BackfillConfig(disableBackfillGroup: $disableBackfillGroup),
            binaries: $this->binaries(),
            nntp: $this->nntp(),
        );
    }

    private function nntp(): NNTPService&MockInterface
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->andReturn([
            'group' => 'alt.test',
            'first' => self::SERVER_FIRST_RECORD,
            'last' => 200_000,
        ]);

        return $nntp;
    }

    private function binaries(): BinariesService&MockInterface
    {
        $binaries = Mockery::mock(BinariesService::class);
        $binaries->shouldReceive('logIndexerStart')->andReturnNull();
        $binaries->shouldReceive('getMessageBuffer')->andReturn(20_000);
        $binaries->shouldReceive('daytopost')->andReturn('90000');
        $binaries->shouldReceive('postdate')->andReturn(1_600_000_000);
        $binaries->shouldReceive('scan')->andReturnUsing(
            function (array $groupArr, int $first, int $last, string $type): array {
                $this->scanWindows[] = ['first' => $first, 'last' => $last];

                return [];
            }
        );

        return $binaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupRow(): array
    {
        return [
            'id' => 1,
            'name' => 'alt.test',
            'first_record' => self::GROUP_FIRST_RECORD,
            'backfill_target' => 30,
        ];
    }

    private function addGroup(int $firstRecord = self::GROUP_FIRST_RECORD): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.test',
            'first_record' => $firstRecord,
            'first_record_postdate' => '2026-07-01 00:00:00',
            'backfill_target' => 30,
            'backfill' => true,
        ]);
    }
}
