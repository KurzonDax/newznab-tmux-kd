<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wall-clock limit, in seconds, for each step of the tmux Fix Names chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([['name' => 'fix_names_timeout', 'value' => '1200']]);
    }

    public function down(): void
    {
        DB::table('settings')->where('name', 'fix_names_timeout')->delete();
    }
};
