<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesRecoveryReleaseSchema
{
    /**
     * Written by hand rather than through `Tests\Support\ProductionTables`, whose SQLite DDL the
     * MariaDB consumer `RecoveryPublicationMariaDbTest` cannot execute. The columns and keys below
     * still have to match `database/schema/mariadb-schema.sql`.
     */
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
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['name', 'searchname', 'searchname_normalized', 'display_name', 'guid', 'leftguid', 'fromname'] as $name) {
                $table->string($name);
            }
            foreach (['totalpart', 'declaredfiles', 'firstarticle', 'lastarticle', 'groups_id', 'size', 'categories_id', 'predb_id'] as $name) {
                $table->unsignedBigInteger($name)->nullable();
            }
            foreach (['passwordstatus', 'haspreview', 'nfostatus', 'nzbstatus', 'isrenamed', 'is_trusted_name', 'iscategorized'] as $name) {
                $table->integer($name)->default(0);
            }
            $table->dateTime('adddate');
            $table->dateTime('postdate');
            $table->double('completion')->default(0);
            $table->binary('collectionhash', 20, true)->nullable()->unique();
            $table->timestamp('recovery_claimed_at')->nullable();
            $table->uuid('recovery_claim_token')->nullable();
            foreach (['repair', 'rescan'] as $stage) {
                $table->string($stage.'_outcome')->nullable();
                $table->timestamp($stage.'_attempted_at')->nullable();
                $table->float($stage.'_target_completion')->nullable();
                $table->float($stage.'_evaluated_target_completion')->nullable();
            }
            $table->timestamp('nzb_creation_claimed_at')->nullable();
            $table->string('nzb_creation_claim_token')->nullable();
            $table->unique('guid');
        });
        Schema::create('release_regexes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('collection_regex_id');
            $table->unsignedInteger('naming_regex_id');
            $table->primary(['releases_id', 'collection_regex_id', 'naming_regex_id']);
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->unsignedInteger('groups_id');
            $table->unique(['releases_id', 'groups_id']);
        });
    }
}
