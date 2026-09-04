<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillConfig;
use App\Services\Binaries\BinariesConfig;
use App\Services\BookService;
use App\Services\NfoService;
use App\Services\TvProcessing\TvProcessingPipeline;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * A settings row that was never seeded used to be read as 0 (or ''), because the
 * `settingValue() !== ''` guard only caught the cleared-row case and let the missing-row
 * null fall through to the cast. Since settingsUpdate() only UPDATEs, the admin form could
 * never create the row, so the wrong value was permanent. Every converted consumer must now
 * resolve a missing row to its own coded default while leaving a stored value untouched.
 */
class MissingSettingRowDefaultsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();

        parent::tearDown();
    }

    public function test_binaries_config_falls_back_to_its_coded_defaults(): void
    {
        $config = BinariesConfig::fromSettings();

        $this->assertSame(20000, $config->messageBuffer);
        $this->assertTrue($config->partRepair);
        $this->assertFalse($config->newGroupScanByDays);
        $this->assertSame(50000, $config->newGroupMessagesToScan);
        $this->assertSame(3, $config->newGroupDaysToScan);
        $this->assertSame(15000, $config->partRepairLimit);
        $this->assertSame(3, $config->partRepairMaxTries);
    }

    public function test_binaries_config_keeps_an_explicitly_stored_zero(): void
    {
        $this->storeSettings([
            'partrepair' => '0',
            'maxpartrepair' => '0',
        ]);

        $config = BinariesConfig::fromSettings();

        $this->assertFalse($config->partRepair);
        $this->assertSame(0, $config->partRepairLimit);
    }

    public function test_binaries_config_reads_a_stored_value(): void
    {
        $this->storeSettings([
            'maxmssgs' => '5000',
            'newgroupscanmethod' => '1',
            'partrepairmaxtries' => '7',
        ]);

        $config = BinariesConfig::fromSettings();

        $this->assertSame(5000, $config->messageBuffer);
        $this->assertTrue($config->newGroupScanByDays);
        $this->assertSame(7, $config->partRepairMaxTries);
    }

    public function test_backfill_config_falls_back_to_its_coded_defaults(): void
    {
        $config = BackfillConfig::fromSettings();

        $this->assertSame('2012-08-14', $config->safeBackFillDate);
        $this->assertSame('backfill', $config->safePartRepair);
        $this->assertFalse($config->disableBackfillGroup);
    }

    public function test_backfill_config_keeps_an_explicitly_stored_zero(): void
    {
        $this->storeSettings([
            'safepartrepair' => '0',
            'disablebackfillgroup' => '0',
        ]);

        $config = BackfillConfig::fromSettings();

        $this->assertSame('backfill', $config->safePartRepair);
        $this->assertFalse($config->disableBackfillGroup);
    }

    public function test_backfill_config_reads_stored_values(): void
    {
        $this->storeSettings([
            'safebackfilldate' => '2015-01-01',
            'safepartrepair' => '1',
            'disablebackfillgroup' => '1',
        ]);

        $config = BackfillConfig::fromSettings();

        $this->assertSame('2015-01-01', $config->safeBackFillDate);
        $this->assertSame('update', $config->safePartRepair);
        $this->assertTrue($config->disableBackfillGroup);
    }

    public function test_the_nfo_batch_limit_falls_back_to_its_coded_default(): void
    {
        $this->assertSame(100, $this->nfoBatchLimit());
    }

    public function test_the_nfo_batch_limit_keeps_an_explicitly_stored_zero(): void
    {
        $this->storeSettings(['maxnfoprocessed' => '0']);

        $this->assertSame(0, $this->nfoBatchLimit());
    }

    public function test_the_nfo_batch_limit_reads_a_stored_value(): void
    {
        $this->storeSettings(['maxnfoprocessed' => '250']);

        $this->assertSame(250, $this->nfoBatchLimit());
    }

    public function test_the_book_service_falls_back_to_its_coded_defaults(): void
    {
        $service = new BookService;

        $this->assertSame(300, $service->bookqty);
        $this->assertSame(1000, $service->sleeptime);
    }

    public function test_the_book_service_keeps_an_explicitly_stored_zero_throttle(): void
    {
        $this->storeSettings(['maxbooksprocessed' => '0', 'amazonsleep' => '0']);

        $service = new BookService;

        $this->assertSame(0, $service->bookqty);
        $this->assertSame(0, $service->sleeptime);
    }

    public function test_the_book_service_reads_stored_values(): void
    {
        $this->storeSettings(['maxbooksprocessed' => '50', 'amazonsleep' => '250']);

        $service = new BookService;

        $this->assertSame(50, $service->bookqty);
        $this->assertSame(250, $service->sleeptime);
    }

    public function test_the_tv_pipeline_batch_limit_falls_back_to_its_coded_default(): void
    {
        $this->assertSame(75, $this->tvBatchLimit());
    }

    public function test_the_tv_pipeline_batch_limit_keeps_an_explicitly_stored_zero(): void
    {
        $this->storeSettings(['maxrageprocessed' => '0']);

        $this->assertSame(0, $this->tvBatchLimit());
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function storeSettings(array $settings): void
    {
        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function nfoBatchLimit(): int
    {
        $method = new ReflectionMethod(NfoService::class, 'getNzbs');

        return $method->invoke(new NfoService);
    }

    private function tvBatchLimit(): int
    {
        $property = new ReflectionProperty(TvProcessingPipeline::class, 'tvqty');

        return $property->getValue(new TvProcessingPipeline);
    }
}
