<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('release_audio_evidence_tracks', function (Blueprint $table): void {
            $table->text('fingerprint')->nullable()->after('disc_id_like')
                ->comment('Compressed base64 Chromaprint fingerprint of the first 120 decoded seconds');
            $table->char('fingerprint_hash', 64)->nullable()->after('fingerprint');
            $table->unsignedTinyInteger('fingerprint_algorithm')->nullable()->after('fingerprint_hash')
                ->comment('Chromaprint algorithm number as fpcalc numbers it');
            $table->string('fingerprint_generator_version', 128)->nullable()->after('fingerprint_algorithm');
        });

        if (! Schema::hasTable('service_statuses') || ! Schema::hasColumn('service_statuses', 'probe_identifier')) {
            return;
        }

        $now = now();
        DB::table('service_statuses')->updateOrInsert(
            ['slug' => 'chromaprint'],
            [
                'name' => 'Chromaprint',
                'endpoint_url' => null,
                'check_type' => 'probe',
                'probe_identifier' => 'chromaprint',
                'status' => 'operational',
                'is_enabled' => true,
                'sort_order' => (int) DB::table('service_statuses')->max('sort_order') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_statuses')) {
            DB::table('service_statuses')->where('slug', 'chromaprint')->delete();
        }

        Schema::table('release_audio_evidence_tracks', function (Blueprint $table): void {
            $table->dropColumn([
                'fingerprint',
                'fingerprint_hash',
                'fingerprint_algorithm',
                'fingerprint_generator_version',
            ]);
        });
    }
};
