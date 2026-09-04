<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\MissedPartHandler;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `partrepairmaxtries` is an unvalidated text input whose value is an attempt budget.
 * Stored as 0 it selected nothing for repair and, because cleanup deletes every row whose
 * attempts reached the maximum, wiped the whole missed-parts queue for the group on each
 * pass. The `partrepair` on/off setting is the sanctioned disable switch, so anything
 * below 1 is misconfiguration and now resolves to a single attempt at the config boundary.
 */
class PartRepairAttemptBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::dropIfExists('missed_parts');
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['numberid', 'groups_id']);
        });
    }

    protected function tearDown(): void
    {
        // The settings table outlives this class on the shared in-memory connection.
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('name', 'partrepairmaxtries')->delete();
        }

        Schema::dropIfExists('missed_parts');

        parent::tearDown();
    }

    public function test_a_stored_zero_maximum_resolves_to_a_single_attempt(): void
    {
        $this->storeMaxTries('0');

        $this->assertSame(1, BinariesConfig::fromSettings()->partRepairMaxTries);
    }

    public function test_a_stored_negative_maximum_resolves_to_a_single_attempt(): void
    {
        $this->storeMaxTries('-3');

        $this->assertSame(1, BinariesConfig::fromSettings()->partRepairMaxTries);
    }

    public function test_a_stored_positive_maximum_passes_through_unchanged(): void
    {
        $this->storeMaxTries('1');
        $this->assertSame(1, BinariesConfig::fromSettings()->partRepairMaxTries);

        $this->storeMaxTries('7');
        $this->assertSame(7, BinariesConfig::fromSettings()->partRepairMaxTries);
    }

    /**
     * The wipe regression: with a maximum of 0, cleanup's `attempts >= 0` matched every
     * row in the group, so parts recorded on one pass were gone by the next one.
     */
    public function test_a_stored_zero_maximum_no_longer_empties_the_queue_on_every_pass(): void
    {
        $this->storeMaxTries('0');

        $handler = $this->handlerFromSettings();
        $handler->addMissingParts([100, 101], 7);
        $handler->cleanupExhaustedParts(7);

        $this->assertSame([100, 101], DB::table('missed_parts')->where('groups_id', 7)->orderBy('numberid')->pluck('numberid')->all());
        $this->assertCount(2, $handler->getMissingParts(7));
    }

    public function test_a_failed_download_ages_a_part_once_per_repair_pass(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        $handler->addMissingParts([100], 7);
        $service = $this->repairService($handler, null);
        $group = ['id' => 7, 'name' => 'alt.test'];

        $service->partRepair($group);
        $this->assertSame(1, (int) DB::table('missed_parts')->where('numberid', 100)->value('attempts'));

        $service->partRepair($group);
        $this->assertSame(2, (int) DB::table('missed_parts')->where('numberid', 100)->value('attempts'));

        $service->partRepair($group);
        $this->assertFalse(DB::table('missed_parts')->where('numberid', 100)->exists());
    }

    public function test_a_downloaded_range_that_does_not_repair_the_part_ages_it_once(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        $handler->addMissingParts([100], 7);

        $this->repairService($handler, [])->partRepair(['id' => 7, 'name' => 'alt.test']);

        $this->assertSame(1, (int) DB::table('missed_parts')->where('numberid', 100)->value('attempts'));
    }

    public function test_a_repaired_part_is_removed_without_being_aged(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        $handler->addMissingParts([100], 7);

        $this->repairService($handler, [[
            'Number' => '100',
            'Subject' => 'Usenet Index Post',
            'Date' => '4 Sep 2026 12:00:00 GMT',
        ]])->partRepair(['id' => 7, 'name' => 'alt.test']);

        $this->assertFalse(DB::table('missed_parts')->where('numberid', 100)->exists());
    }

    private function handlerFromSettings(): MissedPartHandler
    {
        $config = BinariesConfig::fromSettings();

        return new MissedPartHandler($config->partRepairLimit, $config->partRepairMaxTries, $config->sqlChunkSize);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $headers
     */
    private function repairService(MissedPartHandler $handler, ?array $headers): BinariesService
    {
        return new BinariesService(
            config: new BinariesConfig(partRepairMaxTries: 3, echoCli: false),
            missedPartHandler: $handler,
            nntp: new PartRepairNntpStub($headers),
        );
    }

    private function storeMaxTries(string $value): void
    {
        DB::table('settings')->updateOrInsert(['name' => 'partrepairmaxtries'], ['value' => $value]);
    }
}

final class PartRepairNntpStub extends NNTPService
{
    /**
     * @param  array<int, array<string, mixed>>|null  $headers
     */
    public function __construct(private readonly ?array $headers) {}

    public function getOverview(mixed $range = null, bool $names = true, bool $forceNames = true): mixed
    {
        return $this->headers;
    }

    public function __destruct() {}
}
