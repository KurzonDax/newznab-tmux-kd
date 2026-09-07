<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class OrphanedCollectionRecoveryTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->binary('collectionhash')->nullable();
            $table->string('name')->default('');
            $table->string('searchname')->default('');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id')->nullable();
            $table->binary('collectionhash');
            $table->integer('groups_id');
            $table->string('subject');
            $table->integer('filecheck');
            $table->integer('declaredfiles');
            $table->integer('totalfiles');
            $table->bigInteger('filesize');
            $table->dateTime('dateadded');
            $table->dateTime('added');
            $table->dateTime('last_seen_at');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('collections_id');
            $table->string('name');
            $table->integer('totalparts');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('binaries_id');
            $table->integer('partnumber');
            $table->integer('size');
            $table->string('messageid');
        });
        DB::table('usenet_groups')->insert(['id' => 1]);
        DB::table('collections')->insert([
            'id' => 1, 'releases_id' => 99, 'collectionhash' => hex2bin(str_repeat('ab', 20)),
            'groups_id' => 1, 'subject' => 'Example', 'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'declaredfiles' => 1, 'totalfiles' => 1, 'filesize' => 1024,
            'dateadded' => now()->subDay(), 'added' => now()->subDay(), 'last_seen_at' => now()->subDay(),
        ]);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'name' => '"Example.rar" yEnc', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'partnumber' => 1, 'size' => 1024, 'messageid' => 'sanitized@example.invalid']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_report_is_read_only_and_reviewed_apply_only_reopens_the_stale_link(): void
    {
        $before = (array) DB::table('collections')->first();
        $manifest = $this->makeTempPath('orphan-manifest', '.json');
        $this->artisan('releases:recover-orphaned-collections', ['--manifest-out' => $manifest])->assertSuccessful();
        self::assertSame($before, (array) DB::table('collections')->first());
        $report = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['entries'][0]['selected']);
        self::assertTrue($report['entries'][0]['eligible']);
        $report['entries'][0]['selected'] = true;
        $report['entries'][0]['reviewed_unintentional_deletion'] = true;
        file_put_contents($manifest, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('releases:recover-orphaned-collections', ['--apply' => true, '--manifest' => $manifest])->assertSuccessful();
        $expected = [...$before, 'releases_id' => null, 'filecheck' => CollectionFileCheckStatus::CompleteCollection->value];
        self::assertSame($expected, (array) DB::table('collections')->first());
        self::assertSame(1, DB::table('parts')->count());
        self::assertSame(0, DB::table('releases')->count());
        $this->artisan('releases:recover-orphaned-collections', ['--apply' => true, '--manifest' => $manifest])->assertSuccessful();
        self::assertSame($expected, (array) DB::table('collections')->first());
    }

    #[DataProvider('changedSnapshots')]
    public function test_apply_rejects_changed_or_unsafe_snapshots(string $change): void
    {
        $manifest = $this->makeTempPath('orphan-rejection', '.json');
        $this->artisan('releases:recover-orphaned-collections', ['--manifest-out' => $manifest])->assertSuccessful();
        $report = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $report['entries'][0]['selected'] = true;
        $report['entries'][0]['reviewed_unintentional_deletion'] = true;
        match ($change) {
            'parent' => DB::table('releases')->insert(['id' => 99]),
            'ingest' => DB::table('collections')->update(['last_seen_at' => now()]),
            'identity' => DB::table('collections')->update(['collectionhash' => random_bytes(20)]),
            'parts' => DB::table('parts')->delete(),
            'part_changed' => DB::table('parts')->update(['messageid' => 'changed@example.invalid']),
            'par2' => DB::table('binaries')->update(['name' => '"Example.par2" yEnc']),
            'ambiguous' => DB::table('binaries')->update(['name' => 'unknown']),
            'state' => DB::table('collections')->update(['filecheck' => CollectionFileCheckStatus::Delete->value]),
            'duplicate' => DB::table('releases')->insert(['id' => 100, 'collectionhash' => hex2bin(str_repeat('ab', 20))]),
            'unreviewed' => $report['entries'][0]['reviewed_unintentional_deletion'] = false,
        };
        file_put_contents($manifest, json_encode($report, JSON_THROW_ON_ERROR));
        $before = (array) DB::table('collections')->first();
        $this->artisan('releases:recover-orphaned-collections', ['--apply' => true, '--manifest' => $manifest])->assertSuccessful();
        self::assertSame($before, (array) DB::table('collections')->first());
    }

    /** @return array<string, array{string}> */
    public static function changedSnapshots(): array
    {
        return array_combine(
            $cases = ['parent', 'ingest', 'identity', 'parts', 'part_changed', 'par2', 'ambiguous', 'state', 'duplicate', 'unreviewed'],
            array_map(static fn (string $case): array => [$case], $cases),
        );
    }
}
