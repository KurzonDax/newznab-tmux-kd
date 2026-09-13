<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\Binaries\HeaderStorageService;
use App\Services\CollectionCleanupService;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPublicationResult;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use App\Services\ObfuscationRecovery\RecoverySegment;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryStorageBatch;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\ObfuscationRecovery\RecoveryWorkClaim;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryStorageTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.fixture', 'obfuscation_recovery_profile' => 'media']);
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, subject TEXT, fromname TEXT, date TEXT,
            xref TEXT, groups_id INTEGER, totalfiles INTEGER, declaredfiles INTEGER DEFAULT 0,
            collectionhash BLOB UNIQUE, collection_regexes_id INTEGER, dateadded TEXT, last_seen_at TEXT,
            last_seen_head_postdate TEXT, last_seen_tail_postdate TEXT, noise TEXT,
            filesize INTEGER DEFAULT 0, filecheck INTEGER DEFAULT 0, releases_id INTEGER)');
        DB::statement('CREATE TABLE collection_groups (collections_id INTEGER, group_name TEXT, UNIQUE(collections_id, group_name))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, binaryhash BLOB, name TEXT, collections_id INTEGER,
            totalparts INTEGER, currentparts INTEGER, filenumber INTEGER, partsize INTEGER, partcheck INTEGER DEFAULT 0,
            UNIQUE(binaryhash, collections_id))');
        DB::statement('CREATE TABLE parts (id INTEGER PRIMARY KEY, binaries_id INTEGER, number INTEGER, messageid TEXT,
            partnumber INTEGER, size INTEGER, UNIQUE(binaries_id, partnumber))');
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_typed_storage_preserves_raw_ordinals_and_replays_without_losing_parts(): void
    {
        [$plan, $claim, $publication] = $this->publication();
        $storage = app(HeaderStorageService::class);
        $first = new RecoverySegment(str_repeat('a', 32), 1, 4000000001, 'first@local', 100);
        $third = new RecoverySegment(str_repeat('a', 32), 2, 4000000002, 'true-three@local', 200);
        $second = new RecoverySegment(str_repeat('a', 32), 3, 4000000003, 'true-two@local', 300);
        $batch = new RecoveryStorageBatch($plan, 1, 'poster', 1700000000, [$first, $third]);
        $collectionId = $storage->storeRecovered($claim, $publication->id, $batch);
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([$collectionId]));
        $this->assertSame($collectionId, $storage->storeRecovered($claim, $publication->id, $batch));
        $storage->storeRecovered($claim, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            [$second, new RecoverySegment('index@local', 1, 4000000004, 'index@local', 50)]));
        $collection = DB::table('collections')->first();
        $this->assertSame(2, (int) $collection->totalfiles);
        $this->assertSame(0, (int) $collection->declaredfiles);
        $this->assertSame(650, (int) $collection->filesize);
        $this->assertSame(4, DB::table('parts')->count());
        $binary = DB::table('binaries')->where('totalparts', 3)->first();
        $this->assertSame(3, (int) $binary->currentparts);
        $this->assertSame(600, (int) $binary->partsize);
        $this->assertSame(['first@local', 'true-three@local', 'true-two@local'], DB::table('parts')
            ->where('binaries_id', $binary->id)->orderBy('partnumber')->pluck('messageid')->all());
        $this->assertSame('"feature.mkv" yEnc', $binary->name);
        $this->assertSame($collectionId, (int) DB::table('obfuscation_recovery_publications')->value('collections_id'));
    }

    public function test_conflicting_replayed_part_rolls_back_only_its_chunk(): void
    {
        [$plan, $claim, $publication] = $this->publication();
        $storage = app(HeaderStorageService::class);
        $batch = new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            [new RecoverySegment(str_repeat('a', 32), 1, 4000000001, 'first@local', 100)]);
        $storage->storeRecovered($claim, $publication->id, $batch);
        try {
            $storage->storeRecovered($claim, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
                [new RecoverySegment(str_repeat('a', 32), 1, 4000000001, 'changed@local', 100)]));
            $this->fail('Conflicting membership must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('recovery_part_conflict', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(['first@local'], DB::table('parts')->pluck('messageid')->all());
    }

    /** @return array{RecoveryPlan,RecoveryWorkClaim,RecoveryPublicationResult} */
    private function publication(): array
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Publish, 'storage-fixture', 1, 'publish', []);
        $claim = $work->claim(RecoveryStage::Publish);
        $plan = new RecoveryPlan(RecoveryAlgorithm::Media, $claim->bundleId, 1, 'alt.fixture', 'epoch', str_repeat('b', 32), [
            new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 1500000, 3, 'feature.mkv', 'mkv'),
            new RecoveryFilePlan('index@local', RecoveryFileRole::Index, 50, 1, 'recovery.par2', 'par2'),
        ], str_repeat('c', 64));
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
            'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'manifest_verified_at' => now(), 'state' => 'ready',
            'groups_id' => 1, 'profile' => $plan->algorithm->value,
        ]);

        return [$plan, $claim, app(RecoveryPublications::class)->register($plan)];
    }
}
