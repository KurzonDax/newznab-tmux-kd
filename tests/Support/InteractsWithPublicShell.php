<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

trait InteractsWithPublicShell
{
    protected function createPublicShellCountTables(): void
    {
        foreach (['user_movies' => 'imdbid', 'user_series' => 'videos_id', 'users_releases' => 'releases_id'] as $name => $identity) {
            if (Schema::hasTable($name)) {
                continue;
            }

            ProductionTables::fromAuthority()->create($name, ['id', 'users_id', $identity]);
        }
    }
}
