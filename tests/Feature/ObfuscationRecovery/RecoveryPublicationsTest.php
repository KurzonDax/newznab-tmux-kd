<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryAlgorithm;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryEvidenceRetention;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryManifest;
use App\Services\ObfuscationRecovery\RecoveryPlan;
use App\Services\ObfuscationRecovery\RecoveryPublications;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryPublicationsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_replay_keeps_one_publication_and_cannot_resurrect_a_deleted_release(): void
    {
        $store = app(RecoveryPublications::class);
        $first = $store->register($this->plan());
        $this->assertSame('registered', $first->outcome);
        $again = $store->register($this->plan());
        $this->assertSame('existing', $again->outcome);
        $this->assertSame($first->id, $again->id);
        $store->tombstone($first->id);
        $deleted = $store->register($this->plan());
        $this->assertSame('tombstoned', $deleted->outcome);
        $this->assertSame($first->id, $deleted->id);
    }

    public function test_changed_inventory_behind_one_index_is_a_conflict(): void
    {
        $store = app(RecoveryPublications::class);
        $first = $store->register($this->plan());
        $conflict = $store->register($this->plan(str_repeat('d', 32)));
        $this->assertSame('conflict', $conflict->outcome);
        $this->assertSame($first->id, $conflict->id);
        $this->assertSame('conflict', $store->register($this->plan())->outcome);
    }

    public function test_tombstone_replay_never_reads_an_expired_original_manifest(): void
    {
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('retired-publication'));
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $store = app(RecoveryPublications::class);
        $old = $this->scopedPlan($artifacts, 'old.group', 'old-epoch');
        $publication = $store->register($old);
        $store->tombstone($publication->id);
        $this->travel(31)->days();
        $report = (new RecoveryEvidenceRetention($artifacts))->step();
        $this->assertSame(1, $report['owners']);
        $this->assertSame(1, $report['artifacts']);
        $new = $this->scopedPlan($artifacts, 'new.group', 'new-epoch');
        $this->assertSame('tombstoned', $store->register($new)->outcome);
    }

    public function test_verified_membership_is_stable_across_capture_provenance_but_rejects_balanced_substitution(): void
    {
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('publication-provenance'));
        $this->app->instance(RecoveryArtifacts::class, $artifacts);
        $store = app(RecoveryPublications::class);
        $first = $store->register($this->scopedPlan($artifacts, 'group.one', 'epoch-one'));
        $second = $store->register($this->scopedPlan($artifacts, 'group.two', 'epoch-two'));
        $this->assertSame('existing', $second->outcome);
        $this->assertSame($first->id, $second->id);
        $conflict = $store->register($this->scopedPlan($artifacts, 'group.two', 'epoch-two', true));
        $this->assertSame('conflict', $conflict->outcome);
        $this->assertSame($first->id, $conflict->id);
    }

    private function scopedPlan(RecoveryArtifacts $artifacts, string $group, string $epoch, bool $substitute = false): RecoveryPlan
    {
        $base = $this->plan();
        $manifest = new RecoveryManifest($artifacts);
        $artifact = $manifest->write($base->files, $group, $epoch, 1, 1, function (RecoveryFilePlan $file) use ($epoch, $substitute): \Generator {
            for ($i = 1; $i <= $file->totalParts; $i++) {
                $id = $file->role === RecoveryFileRole::Index ? $file->identity : (($substitute && $i === 2) ? 'other' : (string) $i).'@fixture';
                yield (object) ['message_id' => $id, 'source_message_id' => '<'.$id.'>', 'article_number' => $file->role === RecoveryFileRole::Index ? 50 : $i,
                    'advertised_bytes' => 100, 'embedded_timestamp_ms' => 1000 * $i, 'source_epoch' => $epoch,
                    'capture_generation' => 1, 'groups_id' => 1, 'raw_subject' => 'fixture', 'poster_identity' => 'fixture',
                    'source_date' => '2026-01-01T00:00:00Z', 'postdate' => '2026-01-01 00:00:00', 'xref' => '', 'metadata_conflict' => false];
            }
        });

        return new RecoveryPlan($base->algorithm, 1, 1, $group, $epoch, $base->setId, $base->files, $artifact->digest, $artifact->bytes);
    }

    private function plan(string $fileId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): RecoveryPlan
    {
        return new RecoveryPlan(RecoveryAlgorithm::Media, 1, 1, 'group.fixture', 'epoch', str_repeat('b', 32), [
            new RecoveryFilePlan($fileId, RecoveryFileRole::Media, 2250400, 4, 'feature.mkv', 'mkv'),
            new RecoveryFilePlan('Index@example.invalid', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2'),
        ], str_repeat('c', 64));
    }
}
