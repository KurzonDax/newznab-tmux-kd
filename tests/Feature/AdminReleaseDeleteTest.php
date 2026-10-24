<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The admin delete the Adult screens' trashcan sends (issue #1167; docs/proposals/adult-redesign/SPEC.md 5.11):
 * POST admin/release-delete/{guid} as JSON removes the release, its NZB, pictures and clip, and bumps the
 * browse cache version so the Adult list's cached total follows. The cart row goes by the database cascade.
 */
final class AdminReleaseDeleteTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    private const GUID = '0123456789abcdef0123456789abcdef';

    private string $covers = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        ProductionTables::fromAuthority()->create('releases', ['id', 'name', 'searchname', 'guid', 'categories_id']);
        // users_releases with the production foreign key declared (ProductionTables emits none), so the delete cascades on SQLite.
        Schema::create('users_releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('releases_id');
            $table->timestamps();
            $table->unique(['users_id', 'releases_id'], 'ix_usercart_userrelease');
            $table->foreign('releases_id', 'FK_ur_releases')->references('id')->on('releases')->cascadeOnDelete();
        });
        $this->covers = $this->makeTempDirectory('release-delete-covers');
        config(['nntmux_settings.covers_path' => $this->covers, 'nntmux_settings.path_to_nzbs' => $this->makeTempDirectory('release-delete-nzbs')]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_admin_s_json_delete_removes_the_release_its_cart_row_files_and_bumps_the_browse_cache_version(): void
    {
        $admin = $this->admin();
        $id = DB::table('releases')->insertGetId(['name' => 'Studio.Scene.1080p', 'searchname' => 'Studio.Scene.1080p', 'guid' => self::GUID, 'categories_id' => 6040]);
        $kept = DB::table('releases')->insertGetId(['name' => 'Another.Scene.720p', 'searchname' => 'Another.Scene.720p', 'guid' => md5('kept'), 'categories_id' => 6040]);
        DB::table('users_releases')->insert([['users_id' => $admin->id, 'releases_id' => $id], ['users_id' => $admin->id, 'releases_id' => $kept]]);
        $nzb = app(NzbService::class)->getNzbPath(self::GUID, 0, true);
        File::put($nzb, '<nzb/>');
        $files = [$nzb];
        foreach (['preview', 'sample'] as $folder) {
            foreach ([self::GUID.'_thumb.jpg', self::GUID.'.jpg'] as $file) {
                File::ensureDirectoryExists($this->covers.'/'.$folder);
                File::put($files[] = $this->covers.'/'.$folder.'/'.$file, 'jpg');
            }
        }
        File::ensureDirectoryExists($this->covers.'/video');
        File::put($files[] = $this->covers.'/video/'.self::GUID.'.mp4', 'mp4');
        Search::shouldReceive('deleteRelease')->once()->with($id);
        $version = ReleaseBrowseService::cacheVersion();

        $this->actingAs($admin)->postJson('/admin/release-delete/'.self::GUID)->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, DB::table('releases')->where('id', $id)->count());
        $this->assertSame(0, DB::table('users_releases')->where('releases_id', $id)->count());
        $this->assertSame(1, DB::table('users_releases')->where('releases_id', $kept)->count());
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertSame($version + 1, ReleaseBrowseService::cacheVersion());
        $this->assertSame('/admin/release-delete/'.self::GUID, route('admin.release-delete', self::GUID, false));
    }

    public function test_a_non_admin_is_refused_and_the_release_is_untouched(): void
    {
        $user = $this->createUserWithRole('User');
        $id = DB::table('releases')->insertGetId(['name' => 'Studio.Scene.1080p', 'searchname' => 'Studio.Scene.1080p', 'guid' => self::GUID, 'categories_id' => 6040]);
        Search::shouldReceive('deleteRelease')->never();
        $version = ReleaseBrowseService::cacheVersion();

        $this->actingAs($user)->postJson('/admin/release-delete/'.self::GUID)->assertForbidden();

        $this->assertSame(1, DB::table('releases')->where('id', $id)->count());
        $this->assertSame($version, ReleaseBrowseService::cacheVersion());
    }
}
