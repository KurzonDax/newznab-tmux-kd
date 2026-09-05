<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesConfig;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\RecordingBinariesService;
use Tests\TestCase;

/**
 * The `maxmssgs` admin field is an unvalidated text input, and the value it writes is a
 * chunk width. Stored as 0 it used to reach both chunk walks intact: header updating
 * recomputed an identical [first, last] window every pass and backfill stepped its window
 * back to exactly where it was, so each looped forever without progressing or erroring.
 * The message buffer is now made safe once, at the BinariesConfig boundary, so a hostile
 * stored value walks the same articles the coded default would.
 */
class MessageBufferClampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The walks narrate every chunk to the console when echocli is on.
        config(['nntmux.echocli' => false]);

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->dateTime('backfill_settled_at')->nullable();
            $table->dateTime('last_updated')->nullable();
        });

        Schema::dropIfExists('predb');
        Schema::create('predb', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('searched')->default(0);
            $table->dateTime('predate')->nullable();
            $table->dateTime('next_predb_search_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        // The settings table outlives this class on the shared in-memory connection.
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('name', 'maxmssgs')->delete();
        }

        Schema::dropIfExists('predb');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_a_stored_zero_message_buffer_resolves_to_the_coded_default(): void
    {
        $this->storeMessageBuffer('0');

        $this->assertSame(20000, BinariesConfig::fromSettings()->messageBuffer);
    }

    public function test_a_stored_negative_message_buffer_resolves_to_the_coded_default(): void
    {
        $this->storeMessageBuffer('-5000');

        $this->assertSame(20000, BinariesConfig::fromSettings()->messageBuffer);
    }

    public function test_a_stored_positive_message_buffer_passes_through_unchanged(): void
    {
        $this->storeMessageBuffer('1');
        $this->assertSame(1, BinariesConfig::fromSettings()->messageBuffer);

        $this->storeMessageBuffer('5000');
        $this->assertSame(5000, BinariesConfig::fromSettings()->messageBuffer);
    }

    /**
     * The steady-state branch: a group that already has a last_record. This is the walk
     * the report describes, where an unclamped zero buffer recomputed the same
     * [first, last] window every iteration -- though it never got that far, because
     * sizing the range divides by the buffer first.
     */
    public function test_the_header_update_walk_terminates_on_a_stored_zero_for_an_existing_group(): void
    {
        $hostile = $this->walkArticleRange('0', lastRecord: 30000);
        $default = $this->walkArticleRange('20000', lastRecord: 30000);

        $this->assertSame($default, $hostile);
        $this->assertContiguousCoverage($hostile, 30001, 70000);
    }

    /**
     * The new-group branch sizes its range without dividing, so an unclamped zero buffer
     * reached the walk intact and spun there.
     */
    public function test_the_header_update_walk_terminates_on_a_stored_zero_for_a_new_group(): void
    {
        $hostile = $this->walkArticleRange('0');
        $default = $this->walkArticleRange('20000');

        $this->assertSame($default, $hostile);
        $this->assertContiguousCoverage($hostile, 30001, 80000);
    }

    public function test_the_backfill_walk_terminates_on_a_stored_zero_and_covers_the_default_range(): void
    {
        $hostile = $this->walkBackfillChunks('0');
        $default = $this->walkBackfillChunks('20000');

        $this->assertSame($default, $hostile);
        $this->assertContiguousCoverage(array_reverse($hostile), 40001, 100000);
    }

    /**
     * Run the header-update article-range walk for a stored `maxmssgs` and report the
     * article windows it asked for. A last_record of 0 takes the new-group branch.
     *
     * @return list<array{first: int, last: int}>
     */
    private function walkArticleRange(string $storedMessageBuffer, int $lastRecord = 0): array
    {
        $this->storeMessageBuffer($storedMessageBuffer);

        $binaries = new RecordingBinariesService(BinariesConfig::fromSettings());
        $groupMySQL = [
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => $lastRecord,
        ];
        $groupNNTP = ['first' => 1, 'last' => 100000];

        $binaries->walkArticleRange($groupMySQL, $groupNNTP, $binaries->articleRange($groupMySQL, $groupNNTP));

        return $binaries->scannedRanges;
    }

    /**
     * Run the backfill chunk walk for a stored `maxmssgs` and report the article windows
     * it asked for. Backfill walks backwards, so the windows come back newest first.
     *
     * @return list<array{first: int, last: int}>
     */
    private function walkBackfillChunks(string $storedMessageBuffer): array
    {
        $this->storeMessageBuffer($storedMessageBuffer);

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.test',
            'first_record' => 100001,
        ]);

        $binaries = new RecordingBinariesService(BinariesConfig::fromSettings());
        $backfill = new BackfillService(
            config: new BackfillConfig,
            binaries: $binaries,
            // The walk only reaches NNTP when a scan comes back without article dates, and
            // the recording scan always supplies them; constructing a real client would
            // read settings and providers this test has nothing to say about.
            nntp: (new ReflectionClass(NNTPService::class))->newInstanceWithoutConstructor(),
        );

        $walk = new ReflectionMethod(BackfillService::class, 'processBackfillChunks');
        $walk->invoke($backfill, ['id' => 1, 'name' => 'alt.binaries.test', 'first_record' => 100001, 'backfill_target' => 30], 40001, 0, 'a.b.test', 1);

        DB::table('usenet_groups')->truncate();

        return $binaries->scannedRanges;
    }

    /**
     * @param  list<array{first: int, last: int}>  $windows
     */
    private function assertContiguousCoverage(array $windows, int $first, int $last): void
    {
        $this->assertNotEmpty($windows, 'the walk scanned nothing');
        $this->assertSame($first, $windows[0]['first']);
        $this->assertSame($last, $windows[count($windows) - 1]['last']);

        foreach ($windows as $index => $window) {
            $this->assertLessThanOrEqual($window['last'], $window['first'], "window {$index} runs backwards");

            if ($index > 0) {
                $this->assertSame(
                    $windows[$index - 1]['last'] + 1,
                    $window['first'],
                    "window {$index} does not resume where the previous one stopped"
                );
            }
        }
    }

    private function storeMessageBuffer(string $value): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'maxmssgs'], ['value' => $value]);
    }
}
