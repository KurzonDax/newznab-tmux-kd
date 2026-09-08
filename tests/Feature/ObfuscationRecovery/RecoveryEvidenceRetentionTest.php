<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryEvidenceRetention;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryReferences;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryEvidenceRetentionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private RecoveryArtifacts $artifacts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', fn (Blueprint $table) => $table->increments('id'));
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        $this->artifacts = new RecoveryArtifacts($this->makeTempDirectory('retained-evidence'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_shared_evidence_survives_until_all_owners_release_it_without_resetting_attempts(): void
    {
        $cache = new RecoveryEvidence($this->artifacts, new RecoveryIdentity);
        $article = new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'abcd', true, false, false, null);
        $cache->store('shared@local', $article);
        $budget = app(RecoveryBudget::class);
        foreach ([1, 2] as $_) {
            $reservation = $budget->reserve('owner', 'construction', 'shared@local', 128, 1024);
            $budget->settle($reservation, null, 0, 'transport_failure');
        }
        $references = new RecoveryReferences;
        DB::transaction(function () use ($references): void {
            $references->retain('publication', '1', 'evidence', hash('sha256', 'shared@local'));
            $references->retain('publication', '2', 'evidence', hash('sha256', 'shared@local'));
        });
        $this->travel(31)->days();
        $retention = new RecoveryEvidenceRetention($this->artifacts);
        $this->assertSame(0, $retention->step()['evidence']);
        $references->release('publication', '1');
        $this->assertSame(0, $retention->step()['evidence']);
        $this->assertEquals($article, $cache->get('shared@local'));
        $references->release('publication', '2');
        $this->assertSame(['owners' => 0, 'evidence' => 1, 'artifacts' => 1], $retention->step());
        $this->assertNull($cache->get('shared@local'));
        $this->assertSame(256, $budget->spent('owner', 'construction'));
        $this->assertNull($budget->reserve('owner', 'construction', 'shared@local', 128, 1024));
    }

    public function test_unattached_artifact_expires_but_an_active_reader_prevents_unlinking(): void
    {
        $artifact = $this->artifacts->put([str_repeat('x', 70000)], 70000);
        $this->travel(31)->days();
        $reader = $this->artifacts->read($artifact);
        $this->assertSame(65536, strlen($reader->current()));
        $retention = new RecoveryEvidenceRetention($this->artifacts);
        $this->assertSame(0, $retention->step()['artifacts']);
        $this->assertSame(70000, strlen(implode('', iterator_to_array($reader))));
        unset($reader);
        $this->assertSame(1, $retention->step()['artifacts']);
        $this->expectExceptionMessage('artifact_missing');
        iterator_to_array($this->artifacts->read($artifact));
    }

    public function test_conflict_summary_survives_evidence_expiry(): void
    {
        $cache = new RecoveryEvidence($this->artifacts, new RecoveryIdentity);
        $cache->store('conflict@local', new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'abcd', true, false, false, null));
        DB::table('obfuscation_recovery_evidence')->update(['state' => 'conflict']);
        $this->travel(31)->days();
        $this->assertSame(1, (new RecoveryEvidenceRetention($this->artifacts))->step()['evidence']);
        $this->assertSame('conflict', DB::table('obfuscation_recovery_evidence')->value('state'));
        $this->expectExceptionMessage('conflicting_article_evidence');
        $cache->get('conflict@local');
    }

    public function test_reacquired_identical_evidence_is_revalidated_after_eviction(): void
    {
        $cache = new RecoveryEvidence($this->artifacts, new RecoveryIdentity);
        $article = new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'abcd', true, false, false, null);
        $cache->store('reacquired@local', $article);
        $this->travel(31)->days();
        $this->assertSame(1, (new RecoveryEvidenceRetention($this->artifacts))->step()['evidence']);
        $cache->store('reacquired@local', $article);
        $this->assertEquals($article, $cache->get('reacquired@local'));
        $this->travel(31)->days();
        (new RecoveryEvidenceRetention($this->artifacts))->step();
        $this->expectExceptionMessage('conflicting_article_evidence');
        $cache->store('reacquired@local', new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'wxyz', true, false, false, null));
    }

    public function test_shorter_rehydration_preserves_the_strongest_retained_prefix_witness(): void
    {
        $cache = new RecoveryEvidence($this->artifacts, new RecoveryIdentity);
        $prefix = str_repeat('a', 16384);
        $longer = $prefix.'original';
        $article = fn (string $data): RecoveryArticle => new RecoveryArticle('opaque', 20000, 1, 1, 1, 20000, $data, false, false, false, null);
        $cache->store('prefix-witness@local', $article($longer));
        $this->travel(31)->days();
        (new RecoveryEvidenceRetention($this->artifacts))->step();
        $cache->store('prefix-witness@local', $article($prefix));
        $this->assertSame($prefix, $cache->get('prefix-witness@local', true)->data);
        $this->travel(31)->days();
        (new RecoveryEvidenceRetention($this->artifacts))->step();
        $this->expectExceptionMessage('conflicting_article_evidence');
        $cache->store('prefix-witness@local', $article($prefix.'modified'));
    }

    public function test_a_rolled_back_artifact_catalog_write_still_has_a_durable_orphan_record(): void
    {
        DB::beginTransaction();
        $artifact = $this->artifacts->put(['rolled-back-object'], 100);
        DB::rollBack();
        $this->assertSame(0, DB::table('obfuscation_recovery_artifacts')->count());
        $this->travel(31)->days();
        $this->assertSame(1, (new RecoveryEvidenceRetention($this->artifacts))->step()['artifacts']);
        $this->expectExceptionMessage('artifact_missing');
        iterator_to_array($this->artifacts->read($artifact));
    }

    public function test_retained_objects_do_not_starve_later_orphans_in_bounded_pages(): void
    {
        $artifact = $this->artifacts->put(['last-page-object'], 100);
        DB::transaction(function (): void {
            foreach (range(1, 100) as $i) {
                $digest = str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
                DB::table('obfuscation_recovery_artifacts')->insert(['digest' => $digest, 'bytes' => 1, 'retained_at' => now()]);
                (new RecoveryReferences)->retain('publication', '1', 'artifact', $digest);
            }
        });
        $this->travel(31)->days();
        $retention = new RecoveryEvidenceRetention($this->artifacts);
        $this->assertSame(0, $retention->step()['artifacts']);
        $this->assertSame(1, $retention->step()['artifacts']);
        $this->assertFalse(DB::table('obfuscation_recovery_artifacts')->where('digest', $artifact->digest)->exists());
        $this->assertSame(100, DB::table('obfuscation_recovery_artifacts')->count());
    }
}
