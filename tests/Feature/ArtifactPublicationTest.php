<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\CollectionReconciliation\ArtifactPublication;
use App\Services\CollectionReconciliation\ArtifactReleaseUpdate;
use App\Services\CollectionReconciliation\ArtifactSourceRevision;
use App\Services\CollectionReconciliation\CollectionOwnership;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Reconciliation\CreatesPostingSchema;
use Tests\TestCase;

class ArtifactPublicationTest extends TestCase
{
    use CreatesPostingSchema;

    private string $guid = '53700000-0000-4000-8000-000000000001';

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('artifact-publication')]);
        DB::purge();
        DB::reconnect();
        $this->createPostingSchema();
        (require database_path('migrations/2026_09_10_140952_create_reconciled_artifact_operations.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => $this->guid, 'name' => 'Original', 'searchname' => 'Original', 'nzbstatus' => 1]);
        $this->original = '<nzb><head><meta type="opaque">preserve</meta></head><file subject="ordinary (1/2)" poster="Poster" date="1767268800"><groups><group>example.group</group></groups><segments><segment number="2" bytes="20">two@example.invalid</segment></segments></file></nzb>';
        DB::table('reconciled_postings')->insert(['release_id' => 1, 'digest' => str_repeat('a', 64),
            'state' => 'published', 'inventory' => '[]', 'decision' => '{}', 'artifact_digest' => hash('sha256', $this->original)]);
        $nzbs = app(NzbService::class);
        file_put_contents($nzbs->getNzbPath($this->guid, $nzbs->getNzbSplitLevel(), true), gzencode($this->original));
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
    }

    public function test_a_source_reserved_after_evidence_cannot_prepare_a_second_artifact(): void
    {
        DB::table('collections')->insert(['id' => 1]);
        $revision = ArtifactSourceRevision::capture(1);
        $otherGuid = '53700000-0000-4000-8000-000000000002';
        DB::table('releases')->insert(['id' => 2, 'guid' => $otherGuid, 'nzbstatus' => 1]);
        DB::table('reconciled_postings')->insert(['release_id' => 2, 'digest' => str_repeat('b', 64),
            'state' => 'published', 'inventory' => '[]', 'decision' => '{}', 'artifact_digest' => hash('sha256', $this->original)]);
        $nzbs = app(NzbService::class);
        file_put_contents($nzbs->getNzbPath($otherGuid, $nzbs->getNzbSplitLevel(), true), gzencode($this->original));
        $target = str_replace('preserve', 'reserved target', $this->original);
        DB::beginTransaction();
        $reserved = app(ArtifactPublication::class)->replace($this->guid, $target, sourceIds: [1]);
        DB::commit();
        $this->assertNotNull($reserved->operationId);
        $this->assertSame($revision, ArtifactSourceRevision::capture(1));
        $rejected = app(ArtifactPublication::class)->replace($otherGuid, $target, sourceIds: [1], expectedSources: [1 => $revision]);
        $this->assertFalse($rejected->success);
        $this->assertSame('source_reserved_by_artifact', $rejected->reason);
        $this->assertSame(1, DB::table('reconciled_artifact_operations')->count());
        $this->assertSame($this->original, $nzbs->readNzbContents($otherGuid));
        $replayed = app(ArtifactPublication::class)->replace($this->guid, $target, sourceIds: [1]);
        $this->assertTrue($replayed->success, $replayed->reason);
        $this->assertSame($reserved->operationId, $replayed->operationId);
    }

    #[DataProvider('populationTiming')]
    public function test_competitor_insertion_is_rechecked_before_rename_but_recognized_target_is_adopted(bool $afterRename): void
    {
        $source = ['groups_id' => 1, 'fromname' => 'Poster', 'declaredfiles' => 2, 'date' => '2026-01-01 12:00:00'];
        DB::table('collections')->insert(['id' => 1, ...$source]);
        $expected = [1 => ArtifactSourceRevision::capture(1)];
        $target = str_replace('preserve', 'frozen target', $this->original);
        $snapshot = ['version' => 1, 'epoch' => 1, 'proof_revision' => 1,
            'population' => ['group' => 1, 'poster' => 'Poster', 'total' => 2, 'from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 13:00:00']];
        DB::beginTransaction();
        $receipt = app(ArtifactPublication::class)->replace($this->guid, $target, sourceIds: [1], expectedSnapshot: $snapshot, expectedSources: $expected);
        DB::commit();
        if ($afterRename) {
            $crash = new class extends ArtifactPublication
            {
                protected function publish(string $temporary, string $path): bool
                {
                    parent::publish($temporary, $path);
                    throw new \RuntimeException('rename before new competitor');
                }
            };
            $this->assertFalse($crash->execute($receipt->operationId)->success);
        }
        DB::table('collections')->insert(['id' => 2, ...$source]);
        $result = app(ArtifactPublication::class)->execute($receipt->operationId);
        $this->assertSame($afterRename, $result->success);
        $this->assertSame($afterRename ? 'committed' : 'abandoned', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertSame($afterRename ? $target : $this->original, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertTrue(DB::table('collections')->where('id', 2)->exists());
    }

    public static function populationTiming(): iterable
    {
        yield 'before rename invalidates proof' => [false];
        yield 'after rename adopts frozen accounting' => [true];
    }

    public function test_recovery_owned_neighbor_is_excluded_consistently_during_preparation_and_execution(): void
    {
        Schema::create('obfuscation_recovery_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('releases_id')->nullable();
            $table->string('guid')->nullable();
            $table->string('state');
        });
        $source = ['groups_id' => 1, 'fromname' => 'Poster', 'declaredfiles' => 2, 'date' => '2026-01-01 12:00:00'];
        DB::table('collections')->insert([['id' => 1, ...$source], ['id' => 2, ...$source]]);
        DB::table('obfuscation_recovery_publications')->insert(['collections_id' => 2, 'state' => 'prepared']);
        $result = app(ArtifactPublication::class)->replace($this->guid, str_replace('preserve', 'target', $this->original), sourceIds: [1],
            expectedSnapshot: ['version' => 1, 'epoch' => 1, 'proof_revision' => 1,
                'population' => ['group' => 1, 'poster' => 'Poster', 'total' => 2, 'from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 13:00:00']],
            expectedSources: [1 => ArtifactSourceRevision::capture(1)]);
        $this->assertTrue($result->success, $result->reason);
        $this->assertTrue(DB::table('collections')->where('id', 2)->exists());
    }

    public function test_dense_recovery_owned_population_defers_without_publication_and_retries_after_shrinking(): void
    {
        Schema::create('obfuscation_recovery_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('releases_id')->nullable();
            $table->string('guid')->nullable();
            $table->string('state');
        });
        $source = ['groups_id' => 1, 'fromname' => 'Poster', 'declaredfiles' => 2, 'date' => '2026-01-01 12:00:00'];
        DB::table('collections')->insert([['id' => 1, ...$source], ['id' => 2, ...$source]]);
        DB::table('obfuscation_recovery_publications')->insert(['collections_id' => 2, 'state' => 'prepared']);
        for ($id = 3; $id <= 257; $id++) {
            DB::table('collections')->insert(['id' => $id, ...$source]);
            DB::table('obfuscation_recovery_publications')->insert(['collections_id' => $id, 'state' => 'prepared']);
        }
        $replace = fn () => app(ArtifactPublication::class)->replace($this->guid, str_replace('preserve', 'target', $this->original), sourceIds: [1],
            expectedSnapshot: ['version' => 1, 'epoch' => 1, 'proof_revision' => 1,
                'population' => ['group' => 1, 'poster' => 'Poster', 'total' => 2, 'from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 13:00:00']],
            expectedSources: [1 => ArtifactSourceRevision::capture(1)]);
        $result = $replace();
        $this->assertFalse($result->success);
        $this->assertSame('source_population_incomplete', $result->reason);
        $this->assertSame(0, DB::table('reconciled_artifact_operations')->count());
        $posting = app(ArtifactPublication::class)->writePosting(Release::findOrFail(1), $this->original,
            population: ['group' => 1, 'poster' => 'Poster', 'total' => 2, 'from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 13:00:00'],
            observedSourceIds: [1]);
        $this->assertSame('source_population_incomplete', $posting->reason);
        $this->assertSame(0, DB::table('reconciled_artifact_operations')->count());
        $this->assertSame($this->original, app(NzbService::class)->readNzbContents($this->guid));
        DB::table('collections')->where('id', 257)->delete();
        $result = $replace();
        $this->assertTrue($result->success, $result->reason);
        $this->assertTrue(DB::table('collections')->where('id', 2)->exists());
    }

    public function test_prepared_artifact_waits_for_complete_population_without_abandoning_its_receipt(): void
    {
        $source = ['groups_id' => 1, 'fromname' => 'Poster', 'declaredfiles' => 2, 'date' => '2026-01-01 12:00:00'];
        DB::table('collections')->insert(['id' => 1, ...$source]);
        $target = str_replace('preserve', 'target', $this->original);
        DB::beginTransaction();
        $receipt = app(ArtifactPublication::class)->replace($this->guid, $target, sourceIds: [1],
            expectedSnapshot: ['version' => 1, 'epoch' => 1, 'proof_revision' => 1,
                'population' => ['group' => 1, 'poster' => 'Poster', 'total' => 2, 'from' => '2026-01-01 11:00:00', 'until' => '2026-01-01 13:00:00']],
            expectedSources: [1 => ArtifactSourceRevision::capture(1)]);
        DB::commit();
        for ($id = 2; $id <= 257; $id++) {
            DB::table('collections')->insert(['id' => $id, ...$source]);
        }
        $result = app(ArtifactPublication::class)->execute($receipt->operationId);
        $this->assertFalse($result->success);
        $this->assertSame('source_population_incomplete', $result->reason);
        $this->assertSame('prepared', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertSame($this->original, app(NzbService::class)->readNzbContents($this->guid));
        DB::table('collections')->where('id', '>', 1)->delete();
        $this->assertTrue(app(ArtifactPublication::class)->execute($receipt->operationId)->success);
        $this->assertSame($target, app(NzbService::class)->readNzbContents($this->guid));
    }

    public function test_trusted_identity_acquired_after_preparation_prevents_a_new_bundle_write(): void
    {
        DB::beginTransaction();
        $receipt = app(ArtifactPublication::class)->replace($this->guid, str_replace('preserve', 'target', $this->original),
            update: new ArtifactReleaseUpdate('reconciliation', independentVideos: true));
        DB::commit();
        DB::table('releases')->where('id', 1)->update(['is_trusted_name' => true, 'searchname' => 'Trusted title']);
        $result = app(ArtifactPublication::class)->execute($receipt->operationId);
        $this->assertFalse($result->success);
        $this->assertSame('trusted_bundle_identity_conflict', $result->reason);
        $this->assertSame('abandoned', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertSame($this->original, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertSame('Trusted title', DB::table('releases')->value('searchname'));
    }

    public function test_search_failure_retries_the_marker_without_reapplying_committed_metadata(): void
    {
        $search = \Mockery::mock();
        $search->shouldReceive('updateRelease')->once()->andThrow(new \RuntimeException('search unavailable'));
        Search::swap($search);
        $result = app(NzbService::class)->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        $this->assertTrue($result->success);
        $this->assertTrue((bool) DB::table('reconciled_artifacts')->value('search_pending'));
        DB::table('releases')->where('id', 1)->update(['nfostatus' => 7]);
        $search = \Mockery::mock();
        $search->shouldReceive('updateRelease')->once()->andReturn(true);
        Search::swap($search);
        $this->assertTrue(app(ArtifactPublication::class)->execute($result->operationId)->success);
        $this->assertFalse((bool) DB::table('reconciled_artifacts')->value('search_pending'));
        $this->assertSame(7, (int) DB::table('releases')->value('nfostatus'));
        $this->assertSame(2, (int) DB::table('reconciled_artifacts')->value('version'));
    }

    public function test_legacy_mismatch_is_recorded_without_inventing_an_adopted_inventory(): void
    {
        $unknown = str_replace('preserve', 'unknown writer', $this->original);
        file_put_contents(app(NzbService::class)->nzbPath($this->guid), gzencode($unknown));
        $result = app(NzbService::class)->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        $this->assertFalse($result->success);
        $this->assertSame('legacy_artifact_conflict', $result->reason);
        $this->assertSame('conflict', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertNull(DB::table('reconciled_artifacts')->value('xml'));
        $this->assertSame($unknown, app(NzbService::class)->readNzbContents($this->guid));
    }

    public function test_orphan_cleanup_preserves_a_live_artifact_and_pending_temporary_file(): void
    {
        $nzbs = app(NzbService::class);
        DB::beginTransaction();
        $receipt = $nzbs->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        DB::commit();
        $path = $nzbs->nzbPath($this->guid);
        $temporary = $path.'.artifact-'.$receipt->operationId.'.tmp';
        file_put_contents($temporary, 'in progress');
        $this->assertFalse($nzbs->deleteOrphanNzb($this->guid, $path));
        $this->assertFalse($nzbs->deleteOrphanNzb($this->guid, $temporary));
        $this->assertFileExists($temporary);
        $this->assertSame($this->original, $nzbs->readNzbContents($this->guid));
    }

    public function test_changed_guid_cancels_prepared_operation_without_recreating_original_path(): void
    {
        DB::beginTransaction();
        $receipt = app(NzbService::class)->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        DB::commit();
        DB::table('releases')->where('id', 1)->update(['guid' => '53700000-0000-4000-8000-000000000099']);
        $this->assertFalse(app(ArtifactPublication::class)->execute($receipt->operationId)->success);
        $this->assertSame('abandoned', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertNull(DB::table('reconciled_artifact_operations')->value('target_xml'));
        $this->assertSame($this->original, app(NzbService::class)->readNzbContents($this->guid));
    }

    public function test_prepared_operation_preserves_an_unknown_physical_digest_as_durable_conflict(): void
    {
        DB::beginTransaction();
        $receipt = app(NzbService::class)->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        DB::commit();
        $third = str_replace('preserve', 'unrecognized external edit', $this->original);
        file_put_contents(app(NzbService::class)->nzbPath($this->guid), gzencode($third));
        $this->assertFalse(app(ArtifactPublication::class)->execute($receipt->operationId)->success);
        $this->assertSame('conflict', DB::table('reconciled_artifact_operations')->value('state'));
        $this->assertSame($third, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertFalse(app(ArtifactPublication::class)->execute($receipt->operationId)->success);
        $this->assertSame($third, app(NzbService::class)->readNzbContents($this->guid));
    }

    public function test_nested_replacement_stages_without_rename_and_outer_rollback_discards_intent(): void
    {
        $nzbs = app(NzbService::class);
        $target = str_replace('</segments>', '<segment number="1" bytes="10">one@example.invalid</segment></segments>', $this->original);
        DB::beginTransaction();
        $result = $nzbs->replaceNzbContents($this->guid, $target);
        $this->assertFalse($result->success);
        $this->assertNotNull($result->operationId);
        $this->assertSame($this->original, $nzbs->readNzbContents($this->guid));
        DB::rollBack();
        $this->assertSame(0, DB::table('reconciled_artifact_operations')->count());
        DB::beginTransaction();
        $result = $nzbs->replaceNzbContents($this->guid, $target);
        DB::commit();
        $this->assertSame($this->original, $nzbs->readNzbContents($this->guid));
        $this->assertTrue(app(ArtifactPublication::class)->execute($result->operationId)->success);
        $this->assertSame($target, $nzbs->readNzbContents($this->guid));
        $this->assertSame(2, (int) DB::table('reconciled_artifacts')->value('version'));
        $this->assertSame(1, (int) DB::table('reconciled_artifacts')->value('epoch'));
    }

    public function test_rename_crash_replays_adoption_and_old_receipt_cannot_overwrite_a_later_version(): void
    {
        $crashing = new class extends ArtifactPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                parent::publish($temporary, $path);
                throw new \RuntimeException('simulated_process_exit_after_rename');
            }
        };
        $this->app->instance(ArtifactPublication::class, $crashing);
        $target = str_replace('preserve', 'changed metadata', $this->original);
        $result = app(NzbService::class)->replaceNzbContents($this->guid, $target);
        $this->assertFalse($result->success);
        $this->assertSame($target, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertSame(1, (int) DB::table('reconciled_artifacts')->value('version'));
        $this->assertSame('prepared', DB::table('reconciled_artifact_operations')->value('state'));
        $coordinator = new ArtifactPublication;
        $this->app->instance(ArtifactPublication::class, $coordinator);
        $this->assertTrue($coordinator->execute($result->operationId)->success);
        $this->assertSame(2, (int) DB::table('reconciled_artifacts')->value('version'));
        $later = str_replace('changed metadata', 'a newer version', $target);
        $this->assertTrue(app(NzbService::class)->replaceNzbContents($this->guid, $later)->success);
        $this->assertTrue($coordinator->execute($result->operationId)->success);
        $this->assertSame($later, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertSame(3, (int) DB::table('reconciled_artifacts')->value('version'));
    }

    public function test_ap_sanitation_reads_fixed_xml_without_mutating_reconciled_artifact(): void
    {
        $nzbs = app(NzbService::class);
        $broken = str_replace('ordinary', "ord\x01inary", $this->original);
        $path = $nzbs->nzbPath($this->guid);
        file_put_contents($path, gzencode($broken));
        $parser = new NzbContentParser($nzbs, new NzbParserService);
        $this->assertSame($this->original, $parser->repairNzb($broken, $path, $this->guid));
        $this->assertSame($broken, $nzbs->readNzbContents($this->guid));
    }

    public function test_source_changed_before_rename_is_abandoned_and_after_rename_is_retained_whole(): void
    {
        DB::table('collections')->insert(['id' => 1, 'groups_id' => 1, 'subject' => 'original', 'releases_id' => 1, 'filecheck' => 4]);
        $target = str_replace('preserve', 'first operation', $this->original);
        DB::beginTransaction();
        $result = app(ArtifactPublication::class)->replace($this->guid, $target, sourceIds: [1]);
        DB::commit();
        $this->assertTrue(CollectionOwnership::protects(1));
        DB::table('collections')->where('id', 1)->update(['subject' => 'new headers']);
        $this->assertFalse(app(ArtifactPublication::class)->execute($result->operationId)->success);
        $this->assertSame($this->original, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertSame('abandoned', DB::table('reconciled_artifact_operations')->where('id', $result->operationId)->value('state'));
        $crashing = new class extends ArtifactPublication
        {
            protected function publish(string $temporary, string $path): bool
            {
                parent::publish($temporary, $path);
                throw new \RuntimeException('simulated_source_rename_crash');
            }
        };
        $result = $crashing->replace($this->guid, $target, sourceIds: [1]);
        DB::table('collections')->where('id', 1)->update(['subject' => 'headers after rename']);
        $this->assertTrue(app(ArtifactPublication::class)->execute($result->operationId)->success);
        $this->assertSame($target, app(NzbService::class)->readNzbContents($this->guid));
        $this->assertSame('headers after rename', DB::table('collections')->where('id', 1)->value('subject'));
        $this->assertNull(DB::table('collections')->where('id', 1)->value('releases_id'));
        $this->assertFalse(CollectionOwnership::protects(1));
    }

    public function test_explicit_nzb_deletion_cancels_prepared_receipt_and_replay_cannot_resurrect_it(): void
    {
        DB::beginTransaction();
        $result = app(NzbService::class)->replaceNzbContents($this->guid, str_replace('preserve', 'target', $this->original));
        DB::commit();
        $this->assertTrue(app(NzbService::class)->deleteNzb($this->guid));
        $this->assertSame('abandoned', DB::table('reconciled_artifact_operations')->where('id', $result->operationId)->value('state'));
        $this->assertFalse(app(ArtifactPublication::class)->execute($result->operationId)->success);
        $this->assertFalse(app(NzbService::class)->readNzbContents($this->guid));
        $this->assertNull(DB::table('reconciled_artifact_operations')->where('id', $result->operationId)->value('target_xml'));
    }
}
