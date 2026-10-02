<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the IGDB values the console lookup already receives: five `consoleinfo` columns, and
 * the developers and publishers, game modes and player perspectives as rows on shared name
 * tables keyed by `consoleinfo_id`, as Movies keeps its people. Nothing is filled here: a
 * stored game fills when a new release of it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consoleinfo', function (Blueprint $table): void {
            $table->text('storyline')->nullable()->comment('IGDB storyline; NULL = none');
            $table->unsignedTinyInteger('critic_score')->nullable()->comment('IGDB aggregated_rating rounded, 0-100; NULL = none');
            $table->unsignedTinyInteger('user_score')->nullable()->comment('IGDB rating rounded, 0-100; NULL = none');
            $table->string('website', 1000)->nullable()->comment('URL of the first IGDB website of type 1 (Official Website); NULL = none');
            $table->timestamp('details_refreshed_at')->nullable()->comment('When IGDB details were last fetched; NULL = never');
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->increments('id')->comment('Company id');
            $table->string('name', 255)->comment('Company name as IGDB first gave it');
            $table->unsignedInteger('igdb_id')->nullable()->comment('IGDB company id');
            $table->unique('igdb_id', 'ux_companies_igdb_id');
            $table->index('name', 'ix_companies_name');
        });

        Schema::create('console_companies', function (Blueprint $table): void {
            $table->unsignedInteger('consoleinfo_id')->comment('consoleinfo.id of the game');
            $table->unsignedInteger('companies_id')->comment('companies.id of the company');
            $table->unsignedTinyInteger('role')->comment('0 developer, 1 publisher');
            $table->unsignedTinyInteger('position')->comment('0-based order within the role');
            $table->primary(['companies_id', 'consoleinfo_id', 'role']);
            $table->index(['consoleinfo_id', 'role', 'position'], 'ix_console_companies_console');
            $table->foreign('consoleinfo_id', 'fk_console_companies_consoleinfo_id')->references('id')->on('consoleinfo')->cascadeOnDelete();
            $table->foreign('companies_id', 'fk_console_companies_companies_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('game_modes', function (Blueprint $table): void {
            $table->increments('id')->comment('Game mode id');
            $table->string('name', 120)->comment('Game mode name as IGDB lists it');
            $table->unsignedInteger('igdb_id')->nullable()->comment('IGDB game mode id; NULL = none known');
            $table->unique('name', 'ux_game_modes_name');
            $table->unique('igdb_id', 'ux_game_modes_igdb_id');
        });

        Schema::create('console_game_modes', function (Blueprint $table): void {
            $table->unsignedInteger('consoleinfo_id')->comment('consoleinfo.id of the game');
            $table->unsignedInteger('game_modes_id')->comment('game_modes.id of the mode');
            $table->unsignedTinyInteger('position')->comment('0-based order as IGDB lists it');
            $table->primary(['game_modes_id', 'consoleinfo_id']);
            $table->index('consoleinfo_id', 'ix_console_game_modes_console');
            $table->foreign('consoleinfo_id', 'fk_console_game_modes_consoleinfo_id')->references('id')->on('consoleinfo')->cascadeOnDelete();
            $table->foreign('game_modes_id', 'fk_console_game_modes_game_modes_id')->references('id')->on('game_modes')->cascadeOnDelete();
        });

        Schema::create('player_perspectives', function (Blueprint $table): void {
            $table->increments('id')->comment('Player perspective id');
            $table->string('name', 120)->comment('Player perspective name as IGDB lists it');
            $table->unsignedInteger('igdb_id')->nullable()->comment('IGDB player perspective id; NULL = none known');
            $table->unique('name', 'ux_player_perspectives_name');
            $table->unique('igdb_id', 'ux_player_perspectives_igdb_id');
        });

        Schema::create('console_player_perspectives', function (Blueprint $table): void {
            $table->unsignedInteger('consoleinfo_id')->comment('consoleinfo.id of the game');
            $table->unsignedInteger('player_perspectives_id')->comment('player_perspectives.id of the perspective');
            $table->unsignedTinyInteger('position')->comment('0-based order as IGDB lists it');
            $table->primary(['player_perspectives_id', 'consoleinfo_id']);
            $table->index('consoleinfo_id', 'ix_console_player_perspectives_console');
            $table->foreign('consoleinfo_id', 'fk_console_player_perspectives_consoleinfo_id')->references('id')->on('consoleinfo')->cascadeOnDelete();
            $table->foreign('player_perspectives_id', 'fk_console_player_perspectives_player_perspectives_id')->references('id')->on('player_perspectives')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_companies');
        Schema::dropIfExists('console_game_modes');
        Schema::dropIfExists('console_player_perspectives');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('game_modes');
        Schema::dropIfExists('player_perspectives');

        Schema::table('consoleinfo', function (Blueprint $table): void {
            $table->dropColumn(['storyline', 'critic_score', 'user_score', 'website', 'details_refreshed_at']);
        });
    }
};
