<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->settings() as $name => $value) {
            DB::table('settings')->insertOrIgnore(['name' => $name, 'value' => $value]);
        }

        if (! Schema::hasTable('service_statuses') || ! Schema::hasColumn('service_statuses', 'probe_identifier')) {
            return;
        }

        $now = now();
        DB::table('service_statuses')->updateOrInsert(
            ['slug' => 'musicbrainz'],
            [
                'name' => 'MusicBrainz',
                'endpoint_url' => null,
                'check_type' => 'probe',
                'probe_identifier' => 'musicbrainz',
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
        DB::table('settings')->whereIn('name', array_keys($this->settings()))->delete();

        if (Schema::hasTable('service_statuses')) {
            DB::table('service_statuses')->where('slug', 'musicbrainz')->delete();
        }
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return [
            'music_identity_enabled' => '1',
            'music_identity_shadow' => '1',
            'music_identity_workers' => '1',
        ];
    }
};
