<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoverySchemaTest extends TestCase
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

    public function test_raw_storage_preserves_large_article_numbers_and_absent_source_counters(): void
    {
        DB::table('obfuscation_recovery_headers')->insert([
            'source_epoch' => 'epoch', 'groups_id' => 1, 'capture_generation' => 1,
            'message_id' => 'Case@example.invalid', 'source_message_id' => '<Case@example.invalid>',
            'message_id_digest' => hash('sha256', 'Case@example.invalid'),
            'article_number' => 4000000001, 'raw_subject' => 'opaque', 'poster_identity' => 'fixture@example.invalid',
            'source_date' => 'Tue, 14 Nov 2023 22:13:20 +0000', 'postdate' => '2023-11-14 22:13:20',
            'key_digest' => str_repeat('a', 64), 'profile' => 'nyuu-rar-sequential-v1',
            'advertised_bytes' => 123, 'embedded_timestamp_ms' => 1700000000000,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $header = DB::table('obfuscation_recovery_headers')->first();
        $this->assertSame(4000000001, $header->article_number);
        $this->assertSame(1700000000000, $header->embedded_timestamp_ms);
        $this->assertNull($header->original_part);
        $this->assertNull($header->advertised_total);
        $this->assertSame('Case@example.invalid', $header->message_id);
        $this->assertFalse(Schema::hasColumn('obfuscation_recovery_headers', 'collections_id'));
    }
}
