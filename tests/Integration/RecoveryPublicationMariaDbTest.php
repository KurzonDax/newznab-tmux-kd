<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\HeaderScanDirection;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\CollectionCleanupService;
use App\Services\CollectionsCleaningService;
use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryConnection;
use App\Services\ObfuscationRecovery\RecoveryDownload;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryEvidenceRetention;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryHeads;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryManifest;
use App\Services\ObfuscationRecovery\RecoveryMaterialization;
use App\Services\ObfuscationRecovery\RecoveryNzbCommit;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use App\Services\ObfuscationRecovery\RecoverySegment;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryStorageBatch;
use App\Services\ObfuscationRecovery\RecoveryWire;
use App\Services\ObfuscationRecovery\RecoveryWork;
use App\Services\ObfuscationRecovery\RecoveryWorkClaim;
use App\Services\ReleaseCreationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ObfuscationRecovery\CreatesRecoveryCbpSchema;
use Tests\Support\ObfuscationRecovery\CreatesRecoveryReleaseSchema;
use Tests\Support\ObfuscationRecovery\InteractsWithRecoveryNntpServer;
use Tests\TestCase;

final class RecoveryPublicationMariaDbTest extends TestCase
{
    use CreatesRecoveryCbpSchema;
    use CreatesRecoveryReleaseSchema;
    use InteractsWithRecoveryNntpServer;
    use IsolatedSqliteDatabase;

    private string $fixtureDatabase = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->fixtureDatabase = 'recovery_publication_'.bin2hex(random_bytes(8));
        $config = ['driver' => 'mariadb', 'host' => 'mariadb', 'port' => 3306, 'database' => null,
            'username' => 'root', 'password' => 'password', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.recovery_admin' => $config]);
        DB::connection('recovery_admin')->statement('CREATE DATABASE `'.$this->fixtureDatabase.'`');
        config(['database.connections.recovery_publication' => [...$config, 'database' => $this->fixtureDatabase],
            'database.default' => 'recovery_publication']);
        DB::purge('recovery_publication');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.fixture', 'obfuscation_recovery_profile' => 'media']);
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $this->createRecoveryCbpSchema();
        $this->createRecoveryReleaseSchema();
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        config(['nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('publication-nzb')]);
        NzbCreationCandidateQuery::flushCapabilityCache();
    }

    public function test_independent_capture_connection_detects_mariadb_through_the_mysql_driver(): void
    {
        $original = config('database.connections.recovery_publication');
        config(['database.connections.recovery_publication.driver' => 'mysql']);
        DB::purge('recovery_publication');
        $capture = null;
        try {
            $capture = RecoveryConnection::open();
            $this->assertSame('mysql', $capture->getDriverName());
            $limits = $capture->selectOne('SELECT @@session.innodb_lock_wait_timeout AS locks, @@session.max_statement_time AS statements');
            $this->assertSame(1, (int) $limits->locks);
            $this->assertSame(1.0, (float) $limits->statements);
        } finally {
            RecoveryConnection::close($capture);
            DB::purge('recovery_publication');
            config(['database.connections.recovery_publication' => $original]);
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        try {
            $this->stopRecoveryServers();
            DB::disconnect('recovery_publication');
            if ($this->fixtureDatabase !== '') {
                DB::connection('recovery_admin')->statement('DROP DATABASE IF EXISTS `'.$this->fixtureDatabase.'`');
            }
            DB::disconnect('recovery_admin');
        } finally {
            $this->tearDownIsolatedDatabase();
            parent::tearDown();
        }
    }

    public function test_concurrent_chunk_replays_and_cleanup_preserve_exact_binary_hashes_and_membership(): void
    {
        [$artifacts, $plan, $claim, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        $segments = array_map(RecoverySegment::fromRecord(...), $rows);
        $batch = new RecoveryStorageBatch($plan, 1, 'poster', 1700000000, $segments);
        $collectionId = app(HeaderStorageService::class)->storeRecovered($claim, $publication->id, $batch);
        $this->assertTrue(function_exists('pcntl_fork'));
        DB::disconnect('recovery_publication');
        DB::disconnect('recovery_admin');
        $outcomes = $this->makeTempDirectory('concurrent-publication');
        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('recovery_publication');
                try {
                    if ($worker === 3) {
                        $count = app(CollectionCleanupService::class)->deleteCollectionsAndDescendants([$collectionId]);
                        if ($count !== 0) {
                            throw new \RuntimeException('Owned collection was deleted.');
                        }
                    } else {
                        app(HeaderStorageService::class)->storeRecovered($claim, $publication->id, $batch);
                    }
                    file_put_contents($outcomes.'/'.$worker, 'ok');
                    DB::disconnect('recovery_publication');
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($outcomes.'/'.$worker, $exception->getMessage());
                    exit(1);
                }
            }
            $children[$worker] = $pid;
        }
        foreach ($children as $worker => $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), file_get_contents($outcomes.'/'.$worker));
        }
        DB::purge('recovery_publication');
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(20, strlen(DB::table('collections')->value('collectionhash')));
        $this->assertSame(16, strlen(DB::table('binaries')->value('binaryhash')));
        $materialization = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialization->run($claim));
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, $publication->id));
        $release = Release::query()->first();
        $result = app(NzbService::class)->createNzbForRelease($release);
        $this->assertTrue($result->success, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release->fresh())->success);
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_missing_parts_before_a_saved_cursor_are_replayed_from_the_sealed_manifest(): void
    {
        [$artifacts, $plan, $claim, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        $batch = new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            array_map(RecoverySegment::fromRecord(...), array_slice($rows, 0, 3)));
        app(HeaderStorageService::class)->storeRecovered($claim, $publication->id, $batch);
        DB::table('parts')->where('messageid', 'Part1@fixture.invalid')->delete();
        $this->assertSame(3, (int) DB::table('obfuscation_recovery_publications')->value('materialized_parts'));
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialize->run($claim));
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_revalidated_revision_resumes_existing_publication_without_a_new_guid(): void
    {
        [$artifacts, $plan, $old, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        app(HeaderStorageService::class)->storeRecovered($old, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            array_map(RecoverySegment::fromRecord(...), array_slice($rows, 0, 2))));
        $guid = DB::table('obfuscation_recovery_publications')->value('guid');
        $work = app(RecoveryWork::class);
        $work->invalidate('publication-fixture', 2);
        $next = new RecoveryPlan($plan->algorithm, $plan->bundleId, 2, $plan->group, $plan->sourceEpoch, $plan->setId,
            $plan->files, $plan->manifestDigest, $plan->manifestBytes);
        DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->update([
            'state' => 'ready', 'sealed_plan' => json_encode($next->toArray(), JSON_THROW_ON_ERROR), 'manifest_verified_at' => now(),
        ]);
        $work->enqueueForBundle(RecoveryStage::Publish, $plan->bundleId, 2, 'publish', []);
        $claim = $work->claim(RecoveryStage::Publish);
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), $work);
        $this->assertSame('materialized', $materialize->run($claim));
        $this->assertSame('obsolete', $materialize->run($old));
        $this->assertSame($guid, DB::table('obfuscation_recovery_publications')->value('guid'));
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(2, json_decode(DB::table('obfuscation_recovery_publications')->value('sealed_plan'), true)['revision']);
    }

    #[DataProvider('handoffStates')]
    public function test_equivalent_provenance_rebuilds_an_unavailable_canonical_owner_without_a_second_release(string $state): void
    {
        [$artifacts, $plan, $old, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        $materialize = app(RecoveryMaterialization::class);
        if (in_array($state, ['created', 'created-blocked', 'finalized'], true)) {
            $this->assertSame('materialized', $materialize->run($old));
            $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($old, $publication->id));
            if ($state === 'finalized') {
                DB::beginTransaction();
                $this->assertTrue(app(NzbService::class)->createNzbForRelease(Release::query()->first())->success);
                DB::rollBack();
                $this->assertSame(0, (int) DB::table('releases')->value('nzbstatus'));
            }
        } else {
            app(HeaderStorageService::class)->storeRecovered($old, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
                array_map(RecoverySegment::fromRecord(...), array_slice($rows, 0, 2))));
        }
        $guid = DB::table('obfuscation_recovery_publications')->value('guid');
        $releaseId = DB::table('releases')->value('id');
        app(RecoveryWork::class)->complete($old, 'interrupted');
        if ($state === 'disabled') {
            DB::table('usenet_groups')->where('id', 1)->update(['obfuscation_recovery_profile' => 'disabled']);
        } else {
            DB::table('obfuscation_recovery_bundles')->where('id', $old->bundleId)->update(['state' => 'coalesced', 'revision' => 2]);
        }
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.second', 'obfuscation_recovery_profile' => 'both']);
        if ($state === 'created-blocked') {
            Schema::table('usenet_groups', fn (Blueprint $table) => $table->integer('minfilestoformrelease')->default(0));
            DB::table('usenet_groups')->where('id', 2)->update(['minfilestoformrelease' => 10]);
        }
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Publish, 'equivalent-fixture', 1, 'publish', []);
        $claim = $work->claim(RecoveryStage::Publish);
        $nextRows = array_map(static function (array $row): array {
            return [...$row, 'group' => 'alt.second', 'group_id' => 2, 'source_epoch' => 'second-epoch',
                'capture_generation' => 2, 'article_number' => $row['article_number'] + 100];
        }, $rows);
        $artifact = $artifacts->put(array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $nextRows), 100000);
        $next = new RecoveryPlan($plan->algorithm, $claim->bundleId, 1, 'alt.second', 'second-epoch', $plan->setId,
            $plan->files, $artifact->digest, $artifact->bytes);
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
            'state' => 'ready', 'groups_id' => 2, 'profile' => $plan->algorithm->value,
            'sealed_plan' => json_encode($next->toArray(), JSON_THROW_ON_ERROR), 'manifest_verified_at' => now(),
        ]);
        for ($i = 0; $i < 5; $i++) {
            $result = $materialize->run($claim);
            if (in_array($result, ['materialized', 'created', 'policy_blocked', 'published'], true)) {
                break;
            }
            $this->assertContains($result, ['canonical_reconciling', 'canonical_reconciled']);
            $this->assertSame(0, NzbCreationCandidateQuery::baseBuilder()->count());
        }
        $this->assertContains($result, ['materialized', 'created', 'policy_blocked', 'published']);
        if ($state === 'created-blocked') {
            $this->assertSame('policy_blocked', $result);
            $this->assertSame('policy_blocked', app(ReleaseCreationService::class)->createRecovered($claim, $publication->id));
            $this->assertSame(0, NzbCreationCandidateQuery::baseBuilder()->count());
            DB::table('usenet_groups')->where('id', 2)->update(['minfilestoformrelease' => 0]);
        }
        $this->assertSame($state === 'finalized' ? 'published' : 'created', app(ReleaseCreationService::class)->createRecovered($claim, $publication->id));
        $release = Release::query()->first();
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame($guid, $release->guid);
        if ($releaseId !== null) {
            $this->assertSame((int) $releaseId, (int) $release->id);
        }
        $this->assertSame($state === 'finalized' ? 1 : 2, (int) $release->groups_id);
        $this->assertSame($state === 'finalized' ? $plan->manifestDigest : $artifact->digest, DB::table('obfuscation_recovery_publications')->value('manifest_digest'));
        $this->assertSame($state === 'finalized' ? 4000000001 : 4000000101, (int) $release->firstarticle);
        $this->assertSame($state === 'finalized' ? 4000000010 : 4000000110, (int) $release->lastarticle);
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    /** @return array<string,array{string}> */
    public static function handoffStates(): array
    {
        return ['coalesced-partial' => ['partial'], 'disabled-original-group' => ['disabled'], 'created-release' => ['created'],
            'created-policy-blocked' => ['created-blocked'], 'finalized-before-database-commit' => ['finalized']];
    }

    public function test_terminal_partial_publication_retires_owned_storage_without_resetting_identity_or_spend(): void
    {
        [$artifacts, $plan, $claim, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        app(HeaderStorageService::class)->storeRecovered($claim, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            array_map(RecoverySegment::fromRecord(...), array_slice($rows, 0, 2))));
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['state' => 'quarantined', 'reason' => 'publication_identity_conflict']);
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update(['state' => 'quarantined']);
        $owner = DB::table('obfuscation_recovery_bundles')->value('owner_digest');
        $budget = app(RecoveryBudget::class);
        foreach ([1, 2] as $_) {
            $attempt = $budget->reserve($owner, 'construction', 'index@fixture.invalid', 128, 1024);
            $budget->settle($attempt, null, 0, 'transport_failure');
        }
        $this->travel(31)->days();
        DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['claim_expires_at' => now()->addMinute()]);
        $retention = new RecoveryEvidenceRetention($artifacts);
        $this->assertSame(0, $retention->step()['owners']);
        $this->assertSame(2, DB::table('parts')->count());
        DB::table('obfuscation_recovery_work')->where('id', $claim->id)->update(['status' => 'obsolete', 'claim_token' => null]);
        $this->assertSame(1, $retention->step()['owners']);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $current = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('quarantined', $current->state);
        $this->assertSame('retired', $current->cleanup_outcome);
        $this->assertSame('{}', $current->sealed_plan);
        $other = new RecoveryPlan($plan->algorithm, $plan->bundleId, 1, 'alt.other', 'other-epoch', $plan->setId,
            $plan->files, str_repeat('d', 64), 100);
        $this->assertSame('conflict', app(RecoveryPublications::class)->register($other)->outcome);
        $this->assertSame(1, DB::table('obfuscation_recovery_publications')->count());
        $this->assertSame(256, $budget->spent($owner, 'construction'));
        $this->assertNull($budget->reserve($owner, 'construction', 'index@fixture.invalid', 128, 1024));
    }

    public function test_a_missing_owned_collection_is_rebuilt_with_the_original_publication_and_guid(): void
    {
        [$artifacts, $plan, $claim, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        $oldCollection = app(HeaderStorageService::class)->storeRecovered($claim, $publication->id,
            new RecoveryStorageBatch($plan, 1, 'poster', 1700000000, array_map(RecoverySegment::fromRecord(...), $rows)));
        $guid = DB::table('obfuscation_recovery_publications')->value('guid');
        DB::table('collections')->where('id', $oldCollection)->delete();
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        for ($i = 0; $i < 5; $i++) {
            $result = $materialize->run($claim);
            if ($result !== 'reconciling') {
                break;
            }
        }
        $this->assertSame('materialized', $result);
        $this->assertSame(1, DB::table('obfuscation_recovery_publications')->count());
        $this->assertSame($guid, DB::table('obfuscation_recovery_publications')->value('guid'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_missing_cbp_after_release_creation_rebuilds_the_same_release_before_normal_nzb_creation(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialize->run($claim));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        DB::table('collections')->where('id', $publication->collections_id)->delete();
        for ($i = 0; $i < 5; $i++) {
            $result = $materialize->run($claim);
            if ($result !== 'reconciling') {
                break;
            }
        }
        $this->assertSame('created', $result);
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame($release->id, (int) DB::table('collections')->value('releases_id'));
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);
        $this->assertSame('published', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame($release->guid, DB::table('releases')->value('guid'));
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_changed_persisted_membership_is_quarantined_without_overwriting_or_publishing(): void
    {
        [$artifacts, $plan, $claim, $rows] = $this->fixture();
        $publication = app(RecoveryPublications::class)->register($plan);
        app(HeaderStorageService::class)->storeRecovered($claim, $publication->id, new RecoveryStorageBatch($plan, 1, 'poster', 1700000000,
            array_map(RecoverySegment::fromRecord(...), $rows)));
        DB::table('parts')->where('messageid', 'Part1@fixture.invalid')->update(['messageid' => 'changed@fixture.invalid']);
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('quarantined', $materialize->run($claim));
        $this->assertSame('quarantined', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame('obsolete', DB::table('obfuscation_recovery_work')->value('status'));
        $this->assertTrue(DB::table('parts')->where('messageid', 'changed@fixture.invalid')->exists());
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_suspended_profile_limits_block_pending_publication_and_the_ordinary_nzb_selector(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialize->run($claim));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        foreach ([0, -1] as $limit) {
            DB::table('settings')->where('name', 'obfuscation_recovery_media_candidate_mib')->update(['value' => $limit]);
            $this->assertSame(0, NzbCreationCandidateQuery::baseBuilder()->count());
            $this->assertFalse(app(NzbService::class)->createNzbForRelease($release)->success);
            $this->assertSame('created', DB::table('obfuscation_recovery_publications')->value('state'));
        }
        DB::table('settings')->where('name', 'obfuscation_recovery_media_candidate_mib')->update(['value' => 20]);
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);
    }

    public function test_a_late_revision_cannot_commit_an_already_verified_nzb_receipt(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialize->run($claim));
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, (int) $publication->id));
        $release = Release::query()->first();
        $xml = app(NzbService::class)->buildNzbContentsForCollection($release, (int) $publication->collections_id);
        $path = $this->makeTempPath('pending-nzb');
        file_put_contents($path, gzencode($xml));
        $commit = app(RecoveryNzbCommit::class);
        $receipt = $commit->verify($release, $path);
        DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->update([
            'revision' => 2, 'sealed_plan' => null, 'manifest_verified_at' => null,
        ]);
        try {
            DB::transaction(fn () => $commit->commit($receipt));
            $this->fail('A stale plan must not become published.');
        } catch (\RuntimeException $error) {
            $this->assertSame('recovery_nzb_plan_obsolete', $error->getMessage());
        }
        $this->assertSame('created', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertFalse(app(NzbService::class)->createNzbForRelease($release)->success);
        $this->assertSame(5, DB::table('parts')->count());
    }

    public function test_stop_switch_defers_new_publication_and_preserves_existing_files(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialize = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialize->run($claim));
        $publication = app(RecoveryPublications::class)->register($plan);
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $creation = app(ReleaseCreationService::class);
        $this->assertSame('admission_pending', $creation->createRecovered($claim, $publication->id));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(5, DB::table('parts')->count());
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $this->assertSame('created', $creation->createRecovered($claim, $publication->id));
        $release = Release::query()->first();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $writer = app(NzbService::class);
        $this->assertSame(0, NzbCreationCandidateQuery::baseBuilder()->count());
        $this->assertTrue($writer->createNzbForRelease($release)->isDeferred());
        $this->assertSame('created', DB::table('obfuscation_recovery_publications')->value('state'));
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        $this->assertSame(1, NzbCreationCandidateQuery::baseBuilder()->count());
        $this->assertTrue($writer->createNzbForRelease($release)->success);
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 0]);
        $this->assertTrue($writer->createNzbForRelease($release->fresh())->success);
        $this->assertSame('published', DB::table('obfuscation_recovery_publications')->value('state'));
    }

    public function test_policy_blocking_keeps_owned_cbp_and_retries_with_the_same_identity(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialization = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialization->run($claim));
        $publication = app(RecoveryPublications::class)->register($plan);
        DB::table('settings')->insert(['name' => 'minfilestoformrelease', 'value' => 3]);
        $creation = app(ReleaseCreationService::class);
        $this->assertSame('policy_blocked', $creation->createRecovered($claim, $publication->id));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, app(CollectionCleanupService::class)->deleteCollectionsAndDescendants(DB::table('collections')->pluck('id')->all()));
        DB::table('settings')->where('name', 'minfilestoformrelease')->update(['value' => 2]);
        $this->assertSame('created', $creation->createRecovered($claim, $publication->id));
        $this->assertSame(0, (int) DB::table('releases')->value('declaredfiles'));
    }

    public function test_filesystem_commit_before_database_rollback_is_adopted_with_the_existing_guid(): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        $materialization = new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class));
        $this->assertSame('materialized', $materialization->run($claim));
        $publication = app(RecoveryPublications::class)->register($plan);
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, $publication->id));
        $release = Release::query()->first();
        $writer = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            protected function moveTemporaryNzbIntoPlace(string $temporaryPath, string $finalPath): bool
            {
                parent::moveTemporaryNzbIntoPlace($temporaryPath, $finalPath);
                throw new \RuntimeException('Injected interruption after filesystem commit.');
            }
        };
        $this->assertFalse($writer->createNzbForRelease($release)->success);
        $this->assertSame(0, (int) $release->fresh()->nzbstatus);
        $this->assertSame('created', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame(5, DB::table('parts')->count());
        $path = app(NzbService::class)->nzbPath($release->guid);
        $before = hash_file('sha256', $path);
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release->fresh())->success);
        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertSame('published', DB::table('obfuscation_recovery_publications')->value('state'));
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(1, DB::table('releases')->count());
    }

    public static function survivorInventories(): array
    {
        return [['missing', 'missing_nzb'], ['exact', 'verified_exact_inventory'],
            ['additional', 'verified_constituent'], ['different', 'membership_not_verified']];
    }

    #[DataProvider('survivorInventories')]
    public function test_duplicate_policy_is_terminal_and_preserves_the_existing_release(string $inventory, string $proof): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class)))->run($claim);
        $publication = app(RecoveryPublications::class)->register($plan);
        $collection = DB::table('collections')->first();
        $existingId = Release::insertRelease([
            'name' => $collection->subject, 'searchname' => $collection->subject, 'totalpart' => 7, 'declaredfiles' => 7,
            'groups_id' => 1, 'guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'postdate' => $collection->date,
            'fromname' => 'existing-poster', 'size' => $collection->filesize, 'categories_id' => Category::OTHER_MISC,
            'isrenamed' => 1, 'is_trusted_name' => true, 'predb_id' => 0, 'nzbstatus' => 1, 'completion' => 100.0,
        ]);
        $before = (array) DB::table('releases')->where('id', $existingId)->first();
        if ($inventory !== 'missing') {
            $nzb = app(NzbService::class);
            $release = Release::query()->findOrFail($existingId);
            $xml = $nzb->buildNzbContentsForCollection($release, (int) $collection->id);
            $this->assertIsString($xml);
            if ($inventory === 'additional') {
                $xml = str_replace('</nzb>', '<file subject="additional.bin"><groups><group>alt.fixture</group></groups><segments><segment number="1" bytes="100">extra@fixture.invalid</segment></segments></file></nzb>', $xml);
            } elseif ($inventory === 'different') {
                $xml = str_replace('Part1@fixture.invalid', 'different@fixture.invalid', $xml);
            }
            file_put_contents($nzb->getNzbPath($release->guid, 0, true), gzencode($xml));
        }
        $creation = app(ReleaseCreationService::class);
        $this->assertSame('duplicate_policy_discarded', $creation->createRecovered($claim, $publication->id));
        $this->assertSame('duplicate_policy_discarded', $creation->createRecovered($claim, $publication->id));
        $this->assertSame($before, (array) DB::table('releases')->where('id', $existingId)->first());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertNull(app(RecoveryNzbCommit::class)->publication((int) $existingId));
        $this->assertSame($proof, DB::table('obfuscation_recovery_publications')->value('survivor_membership'));
        $this->assertSame(str_starts_with($proof, 'verified_'), DB::table('obfuscation_recovery_publications')->value('survivor_nzb_digest') !== null);
    }

    #[DataProvider('enrichmentTransports')]
    public function test_enrichment_worker_hands_off_a_verified_cached_head_after_cbp_cleanup(bool $tls): void
    {
        [$artifacts, $plan, $claim] = $this->fixture();
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->where('id', 1)->update(['obfuscation_recovery_profile' => 'media']);
        DB::table('obfuscation_recovery_bundles')->where('id', $plan->bundleId)->update(['profile' => $plan->algorithm->value, 'groups_id' => 1]);
        (new RecoveryMaterialization($artifacts, app(HeaderStorageService::class), app(RecoveryWork::class)))->run($claim);
        $publication = app(RecoveryPublications::class)->register($plan);
        $this->assertSame('created', app(ReleaseCreationService::class)->createRecovered($claim, $publication->id));
        $release = Release::query()->first();
        $this->assertTrue(app(NzbService::class)->createNzbForRelease($release)->success);
        $this->assertSame(0, DB::table('parts')->count());
        DB::table('obfuscation_recovery_publications')->where('id', $publication->id)->update(['initialization_state' => 'complete']);
        DB::table('obfuscation_recovery_files')->insert([
            'bundle_id' => $plan->bundleId, 'revision' => 1, 'groups_id' => 1, 'profile' => $plan->algorithm->value,
            'run_digest' => str_repeat('c', 64), 'observed_count' => 4, 'start_ms' => 1, 'end_ms' => 4,
            'state' => 'association_verified', 'role' => 'media', 'file_id' => str_repeat('a', 32), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cache = new RecoveryEvidence($artifacts, new RecoveryIdentity);
        $this->app->instance(RecoveryEvidence::class, $cache);
        $data = str_repeat('a', 716800);
        $cache->store('Part1@fixture.invalid', new RecoveryArticle('opaque', 2250400, 1, 4, 1, 716800,
            substr($data, 0, 16384), false, false, false, null));
        $heads = app(RecoveryHeads::class);
        $this->assertTrue($heads->read((int) $release->id, str_repeat('a', 32))->pending());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
        $response = "222 0 <Part1@fixture.invalid> body\r\n=ybegin part=1 total=4 line=128 size=2250400 name=opaque\r\n=ypart begin=1 end=716800\r\n"
            .str_repeat(str_repeat(chr(139), 128)."\r\n", 5600).'=yend size=716800 part=1 pcrc32='.hash('crc32b', $data)."\r\n.\r\n";
        DB::disconnect('recovery_publication');
        DB::disconnect('recovery_admin');
        $provider = $this->server($response, tls: $tls, messageId: 'Part1@fixture.invalid');
        $this->app->instance(RecoveryWire::class, new RecoveryWire(caFile: $this->certificateAuthority));
        $download = app(RecoveryDownload::class);
        $work = app(RecoveryWork::class);
        $this->assertSame('downloaded', $download->run($work->claim(RecoveryStage::Download), [$provider]));
        $head = $heads->read((int) $release->id, str_repeat('a', 32));
        $this->assertSame('head_available', $head->outcome);
        $this->assertSame($data, $head->prefix->data);
        $this->assertSame('evidence_ready', DB::table('obfuscation_recovery_publications')->value('enrichment_outcome'));
        $this->assertSame(1, DB::table('obfuscation_recovery_attempts')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_slots')->whereNotNull('worker_token')->count());
        $this->assertSame(1, (int) DB::table('obfuscation_recovery_attempts')->value('connections_opened'));
        if ($tls) {
            $this->assertSame(2097152, (int) DB::table('obfuscation_recovery_attempts')->value('debited_bytes'));
        }
    }

    /** @return array<string,array{bool}> */
    public static function enrichmentTransports(): array
    {
        return ['plain' => [false], 'tls' => [true]];
    }

    public function test_a_locked_recovery_policy_usage_row_does_not_block_ordinary_scan_progress(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['obfuscation_recovery_profile' => 'both']);
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->id();
            $table->string('groupname');
            $table->string('regex');
            $table->string('description')->default('');
            $table->integer('msgcol')->default(1);
            $table->integer('optype')->default(1);
            $table->integer('status')->default(1);
            $table->timestamp('last_activity')->nullable();
        });
        Schema::create('collection_regexes', function (Blueprint $table): void {
            $table->id();
            $table->string('group_regex');
            $table->string('regex');
            $table->integer('status')->default(1);
            $table->integer('ordinal')->default(0);
        });
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->integer('attempts')->default(0);
        });
        DB::table('binaryblacklist')->insert(['id' => 1, 'groupname' => '.*', 'regex' => '^012345']);
        $headers = [];
        foreach (['Ordinary.Fixture (1/1)', '0123456789abcdefghij'] as $i => $subject) {
            $headers[] = ['Number' => (string) (4000000001 + $i), 'Subject' => $subject, 'From' => 'fixture@example.invalid',
                'Message-ID' => 'm'.$i.'-1700000000000@nyuu', 'Date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'Bytes' => 740000, 'Xref' => ''];
        }
        $provider = NntpProvider::fromConfig(['position' => 1, 'name' => 'fixture', 'host' => 'fixture.invalid']);
        $nntp = \Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('provider')->andReturn($provider);
        $nntp->shouldReceive('getXOVER', 'getOverview')->andReturn($headers);
        $scanner = new BinariesService(config: new BinariesConfig(
            messageBuffer: 20000, compressedHeaders: false, partRepair: true, echoCli: false, headerChunkSize: 10),
            headerStorage: new HeaderStorageService(new CollectionHandler(
                new class extends CollectionsCleaningService
                {
                    public function collectionsCleaner(string $subject, string $groupName = ''): array
                    {
                        return ['id' => 0, 'name' => $subject];
                    }
                }
            )), nntp: $nntp);
        $locker = DB::build(DB::connection()->getConfig());
        $locker->beginTransaction();
        $locker->table('binaryblacklist')->where('id', 1)->lockForUpdate()->first();
        try {
            $started = hrtime(true);
            $summary = $scanner->scan(['id' => 1, 'name' => 'alt.fixture'], 4000000001, 4000000002, HeaderScanDirection::Head);
            $this->assertLessThan(4000000000, hrtime(true) - $started);
            $this->assertFalse($scanner->lastScanWasRejected());
            $this->assertSame(4000000002, $summary['lastArticleNumber']);
            $this->assertSame(1, DB::table('parts')->count());
            $this->assertSame(0, DB::table('missed_parts')->count());
            $this->assertSame(0, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
        } finally {
            $locker->rollBack();
            $locker->disconnect();
        }
        $scanner->scan(['id' => 1, 'name' => 'alt.fixture'], 4000000001, 4000000002, HeaderScanDirection::Head);
        $this->assertNotNull(DB::table('binaryblacklist')->value('last_activity'));
        $this->assertSame(1, DB::table('obfuscation_recovery_scans')->where('complete', true)->count());
        $this->assertSame('stored', DB::table('obfuscation_recovery_scans')->where('complete', true)->value('ordinary_outcome'));
        $this->assertSame(1, DB::table('parts')->count());
    }

    /** @return array{RecoveryArtifacts,RecoveryPlan,RecoveryWorkClaim,list<array<string,mixed>>} */
    private function fixture(): array
    {
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Publish, 'publication-fixture', 1, 'publish', []);
        $claim = $work->claim(RecoveryStage::Publish);
        $files = [new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 2250400, 4, 'fixture.mkv', 'mkv'),
            new RecoveryFilePlan('index@fixture.invalid', RecoveryFileRole::Index, 100, 1, 'recovery.par2', 'par2')];
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('publication-artifacts'));
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $manifest = new RecoveryManifest($artifacts);
        $artifact = $manifest->write($files, 'alt.fixture', 'epoch', 1, 1, static function (RecoveryFilePlan $file): array {
            $rows = [];
            for ($part = 1; $part <= $file->totalParts; $part++) {
                $index = $file->role === RecoveryFileRole::Index;
                $id = $index ? $file->identity : 'Part'.$part.'@fixture.invalid';
                $rows[] = (object) ['message_id' => $id, 'source_message_id' => '<'.$id.'>',
                    'article_number' => 4000000000 + ($index ? 10 : $part), 'advertised_bytes' => 100 + $part,
                    'embedded_timestamp_ms' => 1700000000000 + ($index ? 10 : $part), 'source_epoch' => 'epoch',
                    'capture_generation' => 1, 'groups_id' => 1, 'metadata_conflict' => false,
                    'raw_subject' => 'opaque', 'poster_identity' => 'poster', 'source_date' => '2023-11-14T22:13:20Z',
                    'postdate' => '2023-11-14 22:13:20', 'xref' => ''];
            }

            return $rows;
        });
        $plan = new RecoveryPlan(RecoveryAlgorithm::Media, $claim->bundleId, 1, 'alt.fixture', 'epoch', str_repeat('b', 32),
            $files, $artifact->digest, $artifact->bytes);
        DB::table('obfuscation_recovery_bundles')->where('id', $claim->bundleId)->update([
            'sealed_plan' => json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'manifest_verified_at' => now(), 'state' => 'ready',
            'groups_id' => 1, 'profile' => $plan->algorithm->value,
        ]);

        return [$artifacts, $plan, $claim, iterator_to_array($manifest->read($artifact))];
    }
}
