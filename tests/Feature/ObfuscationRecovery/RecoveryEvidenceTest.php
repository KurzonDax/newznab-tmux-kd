<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryEvidenceTest extends TestCase
{
    use IsolatedSqliteDatabase;

    private RecoveryEvidence $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        $this->cache = new RecoveryEvidence(new RecoveryArtifacts($this->makeTempDirectory('recovery-cache')), new RecoveryIdentity);
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
}
