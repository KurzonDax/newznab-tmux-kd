<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArticle;
use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryCachedReader;
use App\Services\ObfuscationRecovery\RecoveryEvidence;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryIdentity;
use App\Services\ObfuscationRecovery\RecoveryManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryCachedReaderTest extends TestCase
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
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_cached_reads_use_offsets_and_stop_at_the_first_gap_without_padding(): void
    {
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('reader'));
        $manifest = new RecoveryManifest($artifacts);
        $cache = new RecoveryEvidence($artifacts, new RecoveryIdentity);
        $file = new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 1433700, 3, 'video.mkv', 'mkv');
        $index = new RecoveryFilePlan('index@local', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2');
        $artifact = $manifest->write([$file, $index], 'group.fixture', 'epoch', 1, 1, fn (RecoveryFilePlan $f): array => $f->role === RecoveryFileRole::Index ? [$this->row('index@local', 4)] :
                [$this->row('first@local', 1), $this->row('third@local', 2), $this->row('second@local', 3)]);
        $cache->store('first@local', $this->article(1, str_repeat('a', 16384), false));
        $cache->store('third@local', $this->article(3, str_repeat('c', 100), true));
        $cache->store('second@local', $this->article(2, str_repeat('b', 716800), true));
        $reader = new RecoveryCachedReader($manifest, $cache);
        $prefix = $reader->read($artifact, $file);
        $this->assertSame(str_repeat('a', 16384), $prefix->data);
        $this->assertFalse($prefix->complete);
        $cache->store('first@local', $this->article(1, str_repeat('a', 716800), true));
        $full = $reader->read($artifact, $file);
        $this->assertSame(str_repeat('a', 716800).str_repeat('b', 716800).str_repeat('c', 100), $full->data);
        $this->assertTrue($full->complete);
        $this->assertSame(['first@local', 'second@local', 'third@local'], $full->messageIds);
        $bounded = $reader->read($artifact, $file, 800000);
        $this->assertSame(800000, strlen($bounded->data));
        $this->assertFalse($bounded->complete);
        $this->expectExceptionMessage('unknown_manifest_file');
        $reader->read($artifact, new RecoveryFilePlan(str_repeat('b', 32), RecoveryFileRole::Media, 1433700, 3, 'other.mkv', 'mkv'));
    }

    private function article(int $part, string $data, bool $complete): RecoveryArticle
    {
        return new RecoveryArticle('opaque', 1433700, $part, 3, ($part - 1) * 716800 + 1, min($part * 716800, 1433700), $data, $complete, false, false, null);
    }

    private function row(string $id, int $number): object
    {
        return (object) ['message_id' => $id, 'source_message_id' => '<'.$id.'>', 'article_number' => $number,
            'advertised_bytes' => 100, 'embedded_timestamp_ms' => $number * 1000, 'source_epoch' => 'epoch',
            'capture_generation' => 1, 'groups_id' => 1, 'raw_subject' => 'source subject', 'poster_identity' => 'poster',
            'source_date' => '2026-01-01T00:00:00Z', 'postdate' => '2026-01-01 00:00:00', 'xref' => '', 'metadata_conflict' => false];
    }
}
