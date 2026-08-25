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
        Schema::table('releases', function (Blueprint $table): void {
            $table->timestamp('tv_episode_lookup_attempted_at')->nullable()->after('tv_episodes_id');
            $table->index(
                ['videos_id', 'tv_episodes_id', 'postdate', 'tv_episode_lookup_attempted_at'],
                'ix_releases_tv_episode_revisit'
            );
        });

        $windowCutoff = now()->subDays(max(1, (int) config('nntmux.tv_episode_revisit_window_days', 14)));

        DB::table('releases')
            ->where('tv_episodes_id', -4)
            ->where('postdate', '>=', $windowCutoff)
            ->update(['tv_episodes_id' => 0]);

        DB::table('releases')
            ->where('tv_episodes_id', -4)
            ->where('postdate', '<', $windowCutoff)
            ->update(['tv_episodes_id' => -6]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_tv_episode_revisit');
            $table->dropColumn('tv_episode_lookup_attempted_at');
        });
    }
};
