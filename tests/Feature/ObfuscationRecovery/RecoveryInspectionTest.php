<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryInspection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class RecoveryInspectionTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->timestamp('recovery_claimed_at')->nullable();
            $table->uuid('recovery_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->uuid('additional_pp_claim_token')->nullable();
        });
        Schema::create('obfuscation_recovery_publications', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('releases_id');
            $table->string('guid');
            $table->string('state');
            $table->timestamp('deleted_at')->nullable();
            $table->text('sealed_plan');
            $table->integer('writes')->default(0);
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture']);
        DB::table('obfuscation_recovery_publications')->insert(['id' => 1, 'releases_id' => 1, 'guid' => 'fixture', 'state' => 'published', 'sealed_plan' => '{}']);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_replaced_lease_rejects_late_writes_and_old_cleanup_cannot_release_the_successor(): void
    {
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $old = RecoveryInspection::acquire($publication);
        $this->assertNotNull($old);
        $old->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->increment('writes'));
        DB::table('releases')->update(['recovery_claimed_at' => null, 'recovery_claim_token' => null]);
        $new = RecoveryInspection::acquire($publication);
        $this->assertNotNull($new);
        try {
            $old->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->increment('writes'));
            $this->fail('A replaced worker must not save cached observations.');
        } catch (\RuntimeException $error) {
            $this->assertSame('recovery_inspection_claim_lost', $error->getMessage());
        }
        $old->release();
        $new->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->increment('writes'));
        $this->assertSame(2, (int) DB::table('obfuscation_recovery_publications')->value('writes'));
        $new->release();
    }

    public function test_additional_claim_must_match_both_when_acquiring_and_when_saving(): void
    {
        $publication = DB::table('obfuscation_recovery_publications')->first();
        DB::table('releases')->update(['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'owner']);
        $this->assertNull(RecoveryInspection::acquire($publication));
        $this->assertNull(RecoveryInspection::acquire($publication, 'other'));
        $inspection = RecoveryInspection::acquire($publication, 'owner');
        $this->assertNotNull($inspection);
        DB::table('releases')->update(['additional_pp_claim_token' => 'replacement']);
        try {
            $inspection->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->increment('writes'));
            $this->fail('A replaced additional worker must not save observations.');
        } catch (\RuntimeException $error) {
            $this->assertSame('recovery_inspection_claim_lost', $error->getMessage());
        }
        $this->assertSame(0, (int) DB::table('obfuscation_recovery_publications')->value('writes'));
        $inspection->release();
    }

    public function test_a_receipt_for_another_publication_cannot_authorize_a_write(): void
    {
        $publication = DB::table('obfuscation_recovery_publications')->first();
        $inspection = RecoveryInspection::acquire($publication);
        $other = clone $publication;
        $other->id = 2;
        try {
            $inspection->assertPublication($other);
            $this->fail('The receipt must identify the exact publication.');
        } catch (\RuntimeException $error) {
            $this->assertSame('recovery_inspection_scope_mismatch', $error->getMessage());
        } finally {
            $inspection->release();
        }
    }

    public function test_a_tombstone_or_changed_manifest_fences_a_live_inspector(): void
    {
        foreach ([['deleted_at' => now()], ['sealed_plan' => '{"revision":2}']] as $change) {
            DB::table('obfuscation_recovery_publications')->update(['deleted_at' => null, 'sealed_plan' => '{}']);
            $inspection = RecoveryInspection::acquire(DB::table('obfuscation_recovery_publications')->first());
            DB::table('obfuscation_recovery_publications')->update($change);
            try {
                $inspection->mutate(fn (): int => DB::table('obfuscation_recovery_publications')->increment('writes'));
                $this->fail('Changed publication ownership must stop mutations.');
            } catch (\RuntimeException $error) {
                $this->assertSame('recovery_inspection_claim_lost', $error->getMessage());
            } finally {
                $inspection->release();
            }
        }
        $this->assertSame(0, (int) DB::table('obfuscation_recovery_publications')->value('writes'));
    }
}
