<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionTables;

trait CreatesRecoveryReleaseSchema
{
    private function createRecoveryReleaseSchema(): void
    {
        Schema::table('usenet_groups', fn (Blueprint $table) => $table->unsignedInteger('forced_root_categories_id')->nullable());
        Schema::table('collections', function (Blueprint $table): void {
            $table->unsignedBigInteger('firstarticle')->nullable();
            $table->unsignedBigInteger('lastarticle')->nullable();
            $table->unsignedInteger('absorb_attempts')->default(0);
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('parent_categories_id')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedBigInteger('minsizetoformrelease')->default(0);
        });
        DB::table('categories')->insert(['id' => Category::OTHER_MISC, 'title' => 'Other']);
        ProductionTables::fromAuthority()->create('releases', [
            'id', 'name', 'searchname', 'searchname_normalized', 'display_name', 'guid', 'leftguid', 'fromname',
            'totalpart', 'declaredfiles', 'firstarticle', 'lastarticle', 'groups_id', 'size', 'categories_id', 'predb_id',
            'passwordstatus', 'haspreview', 'nfostatus', 'nzbstatus', 'isrenamed', 'is_trusted_name', 'iscategorized',
            'adddate', 'postdate', 'completion', 'collectionhash', 'recovery_claimed_at', 'recovery_claim_token',
            'repair_outcome', 'repair_attempted_at', 'repair_target_completion', 'repair_evaluated_target_completion',
            'rescan_outcome', 'rescan_attempted_at', 'rescan_target_completion', 'rescan_evaluated_target_completion',
            'nzb_creation_claimed_at', 'nzb_creation_claim_token',
        ]);
        ProductionTables::fromAuthority()->create('release_regexes');
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
            $table->unique(['releases_id', 'groups_id']);
        });
    }
}
