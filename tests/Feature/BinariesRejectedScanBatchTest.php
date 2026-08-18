<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderParser;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NeverBlacklistedService;
use Tests\TestCase;

/**
 * A scan whose headers carry no usable article number (the shifted overview format
 * of issue #116) must be discarded whole: it may not move usenet_groups.last_record
 * and it may not queue the requested range for part repair.
 */
class BinariesRejectedScanBatchTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $group;

    /** @var array<string, mixed> */
    private array $groupNNTP;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('first_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });

        Schema::dropIfExists('missed_parts');
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unique(['numberid', 'groups_id']);
        });

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.boneless',
            'first_record' => 400,
            'first_record_postdate' => '2026-08-10 00:00:00',
            'last_record' => 499,
            'last_record_postdate' => '2026-08-16 00:00:00',
            'last_updated' => null,
        ]);

        $this->group = (array) DB::table('usenet_groups')->find(1);
        $this->groupNNTP = ['group' => 'alt.binaries.boneless', 'first' => 1, 'last' => 900];
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('missed_parts');
        Schema::dropIfExists('usenet_groups');

        parent::tearDown();
    }

    public function test_a_batch_without_usable_article_numbers_is_discarded(): void
    {
        $service = $this->makeService([
            $this->shiftedHeader('kvTj34B5UmHqZQ'),
            $this->shiftedHeader('2O8W9UZ0WrU37hNpMTuHrRZojgZjo [3/10] yEnc (131/165)'),
            $this->shiftedHeader('99999999999999999999999 hash'),
        ]);

        $summary = $service->scan($this->group, 500, 502);

        $this->assertSame([], $summary, 'A batch with no usable article number yields no scan summary.');
        $this->assertTrue($service->lastScanWasRejected());
        $this->assertSame(0, DB::table('missed_parts')->count(), 'A rejected batch must not queue part repair.');
    }

    public function test_a_rejected_batch_does_not_move_the_group_pointer(): void
    {
        $service = $this->makeService([
            $this->shiftedHeader('kvTj34B5UmHqZQ'),
        ]);

        $group = $this->group;
        $summary = $service->scan($group, 500, 502);
        $service->exposedUpdateGroupAfterScan($group, $this->groupNNTP, $summary, 502);

        $stored = DB::table('usenet_groups')->find(1);

        $this->assertSame(499, (int) $stored->last_record);
        $this->assertNull($stored->last_updated, 'The group row must not be touched at all.');
        $this->assertSame(499, (int) $group['last_record']);
    }

    public function test_an_empty_server_response_still_advances_past_the_hole(): void
    {
        $service = $this->makeService([]);

        $group = $this->group;
        $summary = $service->scan($group, 500, 502);
        $service->exposedUpdateGroupAfterScan($group, $this->groupNNTP, $summary, 502);

        $this->assertFalse($service->lastScanWasRejected());
        $this->assertSame(502, (int) DB::table('usenet_groups')->find(1)->last_record);
        $this->assertSame(502, (int) $group['last_record']);
    }

    public function test_numeric_article_numbers_are_still_tracked_and_advance_the_pointer(): void
    {
        // Valid article numbers, subjects without a yEnc part marker, so nothing
        // reaches storage but the numbers still count as received.
        $service = $this->makeService([
            ['Number' => '500', 'Subject' => 'Usenet Index Post', 'Date' => '18 Aug 2026 12:00:00 GMT'],
            ['Number' => '501', 'Subject' => 'Usenet Index Post', 'Date' => '18 Aug 2026 12:01:00 GMT'],
        ]);

        $group = $this->group;
        $summary = $service->scan($group, 500, 502);
        $service->exposedUpdateGroupAfterScan($group, $this->groupNNTP, $summary, 502);

        $this->assertFalse($service->lastScanWasRejected());
        $this->assertSame(500, $summary['firstArticleNumber']);
        $this->assertSame(501, $summary['lastArticleNumber']);
        $this->assertSame(501, (int) DB::table('usenet_groups')->find(1)->last_record);
        $this->assertSame(
            [502],
            DB::table('missed_parts')->pluck('numberid')->map(fn ($number): int => (int) $number)->all(),
            'Only the article the server never returned is queued for part repair.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function shiftedHeader(string $subjectInNumberField): array
    {
        return [
            'Number' => $subjectInNumberField,
            'Subject' => 'poster@example.com',
            'From' => '18 Aug 2026 12:00:00 GMT',
            'Date' => '<part1of1@example.local>',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     */
    private function makeService(array $headers): RejectedScanBinariesProbe
    {
        $nntp = new StubbedXoverNntp;
        $nntp->headers = $headers;

        $config = new BinariesConfig(
            messageBuffer: 20000,
            compressedHeaders: false,
            partRepair: true,
            echoCli: false,
        );

        $service = new RejectedScanBinariesProbe(
            config: $config,
            headerParser: new HeaderParser(new NeverBlacklistedService),
        );
        $service->setNntp($nntp);

        return $service;
    }
}

/**
 * Exposes the protected group-pointer update so a scan summary can be fed to it
 * directly.
 */
final class RejectedScanBinariesProbe extends BinariesService
{
    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $scanSummary
     */
    public function exposedUpdateGroupAfterScan(array &$groupMySQL, array $groupNNTP, array $scanSummary, int $last): void
    {
        $this->updateGroupAfterScan($groupMySQL, $groupNNTP, $scanSummary, $last);
    }
}

/**
 * Returns canned XOVER headers without touching a socket.
 */
final class StubbedXoverNntp extends NNTPService
{
    /** @var array<int, array<string, mixed>> */
    public array $headers = [];

    public function __construct() {}

    public function getXOVER(string $range): mixed
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        return ['group' => $group, 'first' => 1, 'last' => 900];
    }

    public function __destruct() {}
}
