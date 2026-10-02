<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each secondary NNTP provider (enabled, not at position 1) scans headers forward with its own
 * position per group. Article numbers are per-server, so these never share provider 1's
 * `usenet_groups` columns or its `usenet_group_ingested_ranges` rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usenet_group_provider_cursors', function (Blueprint $table): void {
            $table->unsignedInteger('usenet_groups_id')->comment('usenet_groups.id of the group');
            $table->string('provider', 64)->comment('Provider NAME (NNTP_PROVIDER_n_NAME)');
            $table->string('provider_host', 255)->comment('Provider host the position was found on; a different host re-initialises it');
            $table->unsignedBigInteger('last_record')->comment('Newest article number scanned contiguously on this provider');
            $table->dateTime('last_record_postdate')->nullable()->comment('Post date of last_record');
            $table->dateTime('last_advanced_at')->nullable()->comment('When last_record last increased');
            $table->unsignedBigInteger('server_first')->nullable()->comment('First article from the last LIST ACTIVE');
            $table->unsignedBigInteger('server_last')->nullable()->comment('Last article from the last LIST ACTIVE');
            $table->dateTime('server_checked_at')->nullable()->comment('When server_first/server_last were written');
            $table->primary(['usenet_groups_id', 'provider']);
            $table->foreign('usenet_groups_id')->references('id')->on('usenet_groups')->cascadeOnDelete();
        });

        Schema::create('usenet_group_provider_ingested_ranges', function (Blueprint $table): void {
            $table->unsignedInteger('usenet_groups_id')->comment('usenet_groups.id of the group');
            $table->string('provider', 64)->comment('Provider NAME (NNTP_PROVIDER_n_NAME)');
            $table->unsignedBigInteger('first_record')->comment('First article of a scanned range ahead of the cursor');
            $table->unsignedBigInteger('last_record')->comment('Last article of that range');
            $table->dateTime('last_record_postdate')->nullable()->comment('Post date of last_record');
            $table->primary(['usenet_groups_id', 'provider', 'first_record']);
            $table->foreign('usenet_groups_id')->references('id')->on('usenet_groups')->cascadeOnDelete();
        });

        DB::table('settings')->insertOrIgnore(['name' => 'secondary_header_start_hours', 'value' => '36']);
    }

    public function down(): void
    {
        Schema::dropIfExists('usenet_group_provider_ingested_ranges');
        Schema::dropIfExists('usenet_group_provider_cursors');
        DB::table('settings')->where('name', 'secondary_header_start_hours')->delete();
    }
};
