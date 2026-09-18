<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryBudget;
use App\Services\ObfuscationRecovery\RecoveryBudgetOwners;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryStage;
use App\Services\ObfuscationRecovery\RecoveryTransfer;
use App\Services\ObfuscationRecovery\RecoveryWork;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryEvidenceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private RecoveryEvidence $cache;

    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        (require database_path('migrations/2026_09_13_155226_add_recovery_frontier_repair_allowances.php'))->up();
        (require database_path('migrations/2026_09_13_190549_add_recovery_frontier_request_attribution.php'))->up();
        (require database_path('migrations/2026_09_14_110835_add_recovery_handoff_and_process_identity.php'))->up();
        (require database_path('migrations/2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks.php'))->up();
        $this->artifactRoot = $this->makeTempDirectory('recovery-cache');
        $this->cache = new RecoveryEvidence(new RecoveryArtifacts($this->artifactRoot), new RecoveryIdentity);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_cached_prefix_and_complete_evidence_are_distinct_and_case_sensitive(): void
    {
        $prefix = new RecoveryArticle("opaque\xff.mkv", 4, 1, 1, 1, 4, 'ab', false, false, false, null);
        $this->cache->store('<Case@fixture>', $prefix);
        $this->assertEquals($prefix, $this->cache->get('Case@fixture', true));
        $this->assertNull($this->cache->get('Case@fixture'));
        $this->assertNull($this->cache->get('case@fixture', true));
        $full = new RecoveryArticle("opaque\xff.mkv", 4, 1, 1, 1, 4, 'abcd', true, false, false, null);
        $this->cache->store('Case@fixture', $full);
        $this->cache->store('Case@fixture', $full);
        $this->assertEquals($full, $this->cache->get('Case@fixture'));
        $this->assertSame(1, DB::table('obfuscation_recovery_evidence')->count());
        $this->assertSame(0, DB::table('obfuscation_recovery_attempts')->count());
    }

    public function test_declarations_and_shorter_prefixes_can_be_upgraded_without_hiding_quality(): void
    {
        $declaration = new RecoveryArticle('opaque', 10, 1, 1, 1, 10, '', false, false, false, null);
        $prefix = new RecoveryArticle('opaque', 10, 1, 1, 1, 10, 'abcdef', false, false, false, null);
        $this->cache->store('upgrade@local', $declaration);
        $this->assertEquals($declaration, $this->cache->get('upgrade@local', true, 0));
        $this->assertNull($this->cache->get('upgrade@local', true, 6));
        $this->cache->store('upgrade@local', $prefix);
        $this->cache->store('upgrade@local', $declaration);
        $this->assertEquals($prefix, $this->cache->get('upgrade@local', true, 6));
        $this->assertNull($this->cache->get('upgrade@local', true, 7));
        $this->assertNull($this->cache->get('upgrade@local'));
    }

    public function test_a_conflicting_body_cannot_replace_or_leave_a_usable_cached_prefix(): void
    {
        $this->cache->store('fixture@local', new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'ab', false, false, false, null));
        try {
            $this->cache->store('fixture@local', new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'wxyz', true, false, false, null));
            $this->fail('Conflicting article was accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('conflicting_article_evidence', $e->getMessage());
        }
        $this->assertSame('conflict', DB::table('obfuscation_recovery_evidence')->value('state'));
        $this->expectExceptionMessage('conflicting_article_evidence');
        $this->cache->get('fixture@local', true);
    }

    #[DataProvider('artifactLoss')]
    public function test_missing_artifacts_are_unavailable_but_their_fingerprints_still_fence_replacement(bool $corrupt): void
    {
        $article = new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'abcd', true, false, false, null);
        $artifact = $this->cache->store('lost@local', $article);
        if ($corrupt) {
            file_put_contents($this->artifactRoot.'/'.$artifact->digest, 'bad!');
        } else {
            unlink($this->artifactRoot.'/'.$artifact->digest);
        }
        $this->assertNull($this->cache->get('lost@local'));
        $this->cache->store('lost@local', $article);
        $this->assertEquals($article, $this->cache->get('lost@local'));
        unlink($this->artifactRoot.'/'.$artifact->digest);
        try {
            $this->cache->store('lost@local', new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'wxyz', true, false, false, null));
            $this->fail('Lost bytes must not erase their fingerprint.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('conflicting_article_evidence', $exception->getMessage());
        }
        $this->assertSame('conflict', DB::table('obfuscation_recovery_evidence')->value('state'));
    }

    public function test_construction_receipt_conflict_survives_the_ownership_transaction(): void
    {
        DB::table('settings')->where('name', 'obfuscation_recovery_enabled')->update(['value' => 1]);
        DB::table('usenet_groups')->insert(['id' => 1, 'obfuscation_recovery_profile' => 'media']);
        $work = app(RecoveryWork::class);
        $work->enqueue(RecoveryStage::Download, 'receipt-conflict', 1, 'index', ['message_id' => 'conflict@local']);
        DB::table('obfuscation_recovery_bundles')->update(['groups_id' => 1, 'profile' => 'nyuu-media-v1']);
        $claim = $work->claim(RecoveryStage::Download);
        $old = new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'abcd', true, false, false, null);
        $new = new RecoveryArticle('opaque', 4, 1, 1, 1, 4, 'wxyz', true, false, false, null);
        $this->cache->store('conflict@local', $old);
        $owner = DB::table('obfuscation_recovery_bundles')->value('owner_digest');
        $reservation = app(RecoveryBudget::class)->reserve($owner, 'construction', 'conflict@local', 128, 400);
        $transfer = new RecoveryTransfer($new, 'success', null, 20, 5, null, 1, null, 0, true, 1, 4);
        $this->cache->receipts()->record($claim, $reservation, $transfer, false, 0, 'article', 'conflict@local', $new->metadata(), $new->data);
        try {
            $this->cache->resume($claim, 'conflict@local');
            $this->fail('Contradictory handoff must be final.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('conflicting_article_evidence', $exception->getMessage());
        }
        $this->assertSame('conflict', DB::table('obfuscation_recovery_evidence')->value('state'));
        $this->assertTrue((bool) DB::table('obfuscation_recovery_attempts')->value('handoff_conflict'));
        $this->expectExceptionMessage('conflicting_article_evidence');
        $this->cache->get('conflict@local');
    }

    #[DataProvider('finalFailures')]
    public function test_merged_receipts_cannot_hide_older_final_failures(string $failure): void
    {
        $budget = app(RecoveryBudget::class);
        foreach (['first', 'second', 'third'] as $owner) {
            $reservation = $budget->reserve($owner, 'construction', 'shared@local', 128, 400);
            $budget->settle($reservation, 10, 10, $owner === 'first' && $failure === 'semantic_failure' ? $failure : 'success');
            if ($owner === 'first' && $failure === 'handoff_conflict') {
                DB::table('obfuscation_recovery_attempts')->where('id', $reservation->attemptId)->update(['handoff_conflict' => true]);
            }
        }
        (new RecoveryBudgetOwners(new RecoveryIdentity))->merge(['first', 'second', 'third']);
        $this->expectExceptionMessage('conflicting_transfer_receipt');
        $this->cache->receipts()->available((object) ['owner_digest' => 'third'], 'article', 'shared@local');
    }

    public static function finalFailures(): array
    {
        return [['semantic_failure'], ['handoff_conflict']];
    }

    public static function artifactLoss(): array
    {
        return [[false], [true]];
    }
}
