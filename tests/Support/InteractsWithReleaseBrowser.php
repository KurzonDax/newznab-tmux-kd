<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Release;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

trait InteractsWithReleaseBrowser
{
    protected function browserUser(): User
    {
        $user = $this->createUserWithRole('User');
        foreach (['movies', 'audio', 'console', 'books', 'adult', 'pc', 'tv', 'other'] as $root) {
            $permission = Permission::findOrCreate('view '.$root, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    protected function release(string $name, array $attributes = []): int
    {
        $values = Release::factory()->raw([
            'fromname' => '', 'name' => $name, 'searchname' => $name, 'guid' => md5($name), 'categories_id' => 2030,
            'adddate' => '2026-09-13 12:00:00', 'postdate' => '2026-09-12 23:30:00',
            ...$attributes,
        ]);

        return DB::table('releases')->insertGetId(array_intersect_key($values, array_flip(Schema::getColumnListing('releases'))));
    }

    protected function createReleaseSchema(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['name', 'searchname', 'guid', 'display_name', 'fromname', 'imdbid', 'additional_pp_claim_token', 'repair_outcome', 'rescan_outcome'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['categories_id', 'groups_id', 'videos_id', 'tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid'] as $column) {
                $table->integer($column)->nullable();
            }
            foreach (['totalpart', 'grabs', 'comments', 'passwordstatus', 'nfostatus', 'haspreview', 'jpgstatus', 'videostatus', 'isrenamed'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->bigInteger('size')->default(524288000);
            $table->float('completion')->default(100);
            $table->dateTime('adddate')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->unique('guid');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
        });
        Schema::create('users_releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('users_id');
            $table->integer('releases_id');
            $table->timestamps();
            $table->unique(['users_id', 'releases_id']);
        });
        Schema::create('user_movies', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->string('imdbid');
            $table->string('categories')->nullable();
        });
        Schema::create('user_series', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->integer('videos_id');
            $table->string('categories')->nullable();
        });
    }
}
