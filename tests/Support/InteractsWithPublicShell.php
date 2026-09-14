<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait InteractsWithPublicShell
{
    protected function createPublicShellCountTables(): void
    {
        foreach (['user_movies' => 'imdbid', 'user_series' => 'videos_id', 'users_releases' => 'releases_id'] as $name => $identity) {
            if (Schema::hasTable($name)) {
                continue;
            }

            Schema::create($name, function (Blueprint $table) use ($identity): void {
                $table->id();
                $table->unsignedInteger('users_id');
                $table->string($identity);
            });
        }
    }
}
