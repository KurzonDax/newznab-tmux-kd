<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;
use ZipArchive;

/**
 * Every web page and download that serves one release by its guid or id refuses a release whose
 * category the signed-in user has hidden, either by switching off its root (a missing `view …`
 * permission) or by unticking its sub-category. The hidden release is Movies > SD; the visible
 * one is Audio > MP3. The details page is covered by DetailsControllerTest.
 */
final class HiddenCategoryReleaseAccessTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    private const int SD = 2030;

    private const int MP3 = 3010;

    private const string HIDDEN = 'Hidden.Film.2026.SD';

    private const string VISIBLE = 'Visible.Film.2026.1080p';

    private string $nzbDirectory;

    private string $coversRoot;

    private ?User $viewer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        // release_nfos stores MariaDB COMPRESS() output; the fixture stores plain text.
        $this->registerSqliteFunction('UNCOMPRESS', static fn (?string $value): ?string => $value, 1);
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        Schema::table('roles', function (Blueprint $table): void {
            $table->integer('downloadrequests')->default(1000);
        });
        DB::table('root_categories')->insert([
            ['id' => 2000, 'title' => 'Movies'],
            ['id' => 3000, 'title' => 'Audio'],
            ['id' => 6000, 'title' => 'XXX'],
        ]);
        DB::table('categories')->insert([
            ['id' => self::SD, 'title' => 'SD', 'root_categories_id' => 2000],
            ['id' => self::MP3, 'title' => 'MP3', 'root_categories_id' => 3000],
        ]);
        foreach (['2026_02_01_000000_create_release_reports_table', '2026_06_08_000000_add_response_fields_to_release_reports_table', '2026_08_21_090000_create_release_audio_tags_table', '2026_08_27_150100_create_release_video_clips_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        foreach (['releases_groups', 'release_nfos', 'user_downloads', 'media_infos', 'media_info_probes', 'media_info_tracks', 'video_data', 'audio_data', 'release_subtitles'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        DB::table('settings')->insert([
            ['name' => 'nzbsplitlevel', 'value' => '0'],
            ['name' => 'grabstatus', 'value' => '1'],
        ]);
        $this->nzbDirectory = $this->makeTempDirectory('hidden-category-nzbs');
        $this->coversRoot = $this->makeTempDirectory('hidden-category-covers');
        mkdir($this->coversRoot.'/video', 0775, true);
        mkdir($this->coversRoot.'/audiosample', 0775, true);
        config([
            'nntmux_settings.path_to_nzbs' => $this->nzbDirectory.'/',
            'nntmux_settings.covers_path' => $this->coversRoot,
        ]);
        Search::shouldReceive('updateRelease')->andReturnNull();

        $this->stored(self::HIDDEN, self::SD);
        $this->stored(self::VISIBLE, self::MP3);
    }

    protected function tearDown(): void
    {
        $this->viewer = null;
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function hidingModes(): array
    {
        return [
            'the whole root switched off' => ['root', 'Movies'],
            'only the sub-category unticked' => ['sub', 'Movies - SD'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function hidingModesOnly(): array
    {
        return array_map(static fn (array $case): array => [$case[0]], self::hidingModes());
    }

    #[DataProvider('hidingModes')]
    public function test_the_nfo_page_refuses_a_hidden_release(string $mode, string $name): void
    {
        $this->hide($mode);
        $this->assertDenied($this->get('/nfo/'.md5(self::HIDDEN)), $name);
        $this->assertDenied($this->get('/nfo/'.md5(self::HIDDEN).'?modal=1'), $name);
        $this->get('/nfo/'.md5(self::VISIBLE).'?modal=1')->assertOk()->assertSee('NFO for '.self::VISIBLE);
    }

    #[DataProvider('hidingModes')]
    public function test_the_nzb_download_refuses_a_hidden_release_in_a_signed_in_session(string $mode, string $name): void
    {
        $this->hide($mode);
        $this->assertDenied($this->get('/getnzb/'.md5(self::HIDDEN)), $name);
        $this->assertDenied($this->get('/getnzb?id='.md5(self::HIDDEN)), $name);
        $this->assertSame(0, DB::table('user_downloads')->count());

        $visible = $this->get('/getnzb/'.md5(self::VISIBLE))->assertOk();
        $this->assertStringContainsString('<nzb>'.self::VISIBLE.'</nzb>', $visible->streamedContent());
    }

    #[DataProvider('hidingModesOnly')]
    public function test_feed_links_still_download_a_hidden_release_with_or_without_a_session(string $mode): void
    {
        $this->hide($mode);
        $token = (string) $this->viewer()->api_token;

        $this->app['auth']->forgetGuards();
        $guest = $this->get('/getnzb?id='.md5(self::HIDDEN).'&r='.$token)->assertOk();
        $this->assertStringContainsString('<nzb>'.self::HIDDEN.'</nzb>', $guest->streamedContent());

        $signedIn = $this->actingAs($this->viewer())->get('/getnzb?id='.md5(self::HIDDEN).'&r='.$token)->assertOk();
        $this->assertStringContainsString('<nzb>'.self::HIDDEN.'</nzb>', $signedIn->streamedContent());
        $this->assertSame(2, DB::table('user_downloads')->where('users_id', $this->viewer()->id)->count());
    }

    #[DataProvider('hidingModes')]
    public function test_a_session_zip_leaves_out_hidden_releases(string $mode, string $name): void
    {
        $this->hide($mode);
        $response = $this->get('/getnzb?zip=1&id='.md5(self::VISIBLE).','.md5(self::HIDDEN))->assertOk();
        $this->assertSame(['<nzb>'.self::VISIBLE.'</nzb>'], array_values($this->archiveEntries($response)));
        $this->assertSame([$this->releaseId(self::VISIBLE)], DB::table('user_downloads')->pluck('releases_id')->map(static fn ($id): int => (int) $id)->all());

        $this->assertDenied($this->get('/getnzb?zip=1&id='.md5(self::HIDDEN)), $name);
    }

    #[DataProvider('hidingModesOnly')]
    public function test_the_file_list_routes_refuse_a_hidden_release(string $mode): void
    {
        $this->hide($mode);
        $this->assertBare($this->getJson('/release/'.md5(self::HIDDEN).'/files'));
        $this->assertBare($this->get('/release/'.md5(self::HIDDEN).'/files'));
        $this->assertBare($this->getJson('/api/release/'.md5(self::HIDDEN).'/filelist'));

        $this->getJson('/release/'.md5(self::VISIBLE).'/files')->assertOk()->assertJsonPath('release.searchname', self::VISIBLE);
        $this->getJson('/api/release/'.md5(self::VISIBLE).'/filelist')->assertOk()->assertJsonPath('release.searchname', self::VISIBLE);
    }

    #[DataProvider('hidingModesOnly')]
    public function test_media_info_refuses_a_hidden_release(string $mode): void
    {
        $this->hide($mode);
        $this->assertBare($this->getJson('/release/'.$this->releaseId(self::HIDDEN).'/mediainfo'));
        $this->getJson('/release/'.$this->releaseId(self::VISIBLE).'/mediainfo')->assertOk()->assertJsonPath('release_name', self::VISIBLE);
    }

    #[DataProvider('hidingModesOnly')]
    public function test_the_video_and_audio_previews_refuse_a_hidden_release(string $mode): void
    {
        foreach ([self::HIDDEN, self::VISIBLE] as $name) {
            $id = $this->releaseId($name);
            DB::table('releases')->where('id', $id)->update(['videostatus' => 1]);
            file_put_contents($this->coversRoot.'/video/'.md5($name).'.ogv', str_repeat('v', 64));
            DB::table('release_audio_tags')->insert(['releases_id' => $id, 'audio_format' => 'MPEG Audio', 'has_preview' => 1, 'preview_extension' => 'mp3', 'preview_mime' => 'audio/mpeg', 'preview_seconds' => 30, 'preview_bytes' => 64]);
            file_put_contents($this->coversRoot.'/audiosample/'.md5($name).'.mp3', str_repeat('a', 64));
        }
        $this->hide($mode);

        $this->assertBare($this->get('/preview/video/'.md5(self::HIDDEN)));
        $this->assertBare($this->get('/preview/audio/'.md5(self::HIDDEN)));
        $this->get('/preview/video/'.md5(self::VISIBLE))->assertOk()->assertHeader('Content-Type', 'video/ogg');
        $this->get('/preview/audio/'.md5(self::VISIBLE))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    }

    #[DataProvider('hidingModes')]
    public function test_the_basket_never_adds_a_hidden_release(string $mode, string $name): void
    {
        $this->hide($mode);
        $this->postJson('/cart/add', ['id' => md5(self::HIDDEN)])->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => $name.' is hidden in your account preferences.']);
        $this->post('/cart/add', ['id' => md5(self::HIDDEN)])->assertRedirect(route('basket'));
        $this->assertSame(0, DB::table('users_releases')->count());

        $this->postJson('/cart/add', ['id' => md5(self::HIDDEN).','.md5(self::VISIBLE)])->assertOk()
            ->assertJson(['success' => true, 'message' => '1 item(s) added to cart', 'cartCount' => 1]);
        $this->assertSame([$this->releaseId(self::VISIBLE)], DB::table('users_releases')->pluck('releases_id')->map(static fn ($id): int => (int) $id)->all());
    }

    #[DataProvider('hidingModes')]
    public function test_a_hidden_release_cannot_be_reported(string $mode, string $name): void
    {
        $this->hide($mode);
        $this->postJson('/release-report', ['release_id' => $this->releaseId(self::HIDDEN), 'reason' => 'spam'])->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => $name.' is hidden in your account preferences.']);
        $this->assertSame(0, DB::table('release_reports')->count());

        $this->postJson('/release-report', ['release_id' => $this->releaseId(self::VISIBLE), 'reason' => 'spam'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, DB::table('release_reports')->count());
    }

    /** @return array<string, array{string, string, string}> */
    public static function browseAliases(): array
    {
        return [
            'adult' => ['adult', 'view adult', 'Adult'],
            'music' => ['music', 'view audio', 'Audio'],
            'other' => ['other', 'view other', 'Other'],
        ];
    }

    #[DataProvider('browseAliases')]
    public function test_browse_aliases_get_the_permission_check_of_their_canonical_name(string $alias, string $permission, string $name): void
    {
        $this->revoke($permission);
        $this->assertDenied($this->get('/browse/'.$alias), $name);
        $this->assertDenied($this->get('/browse/'.$alias.'/All'), $name);
        $this->assertDenied($this->get('/browse/'.$alias.'/MP3'), $name);
    }

    public function test_the_adult_alias_is_denied_as_its_canonical_name_is(): void
    {
        $this->revoke('view adult');
        $this->assertDenied($this->get('/browse/xxx'), 'Adult');
        $this->assertDenied($this->get('/browse/adult'), 'Adult');
    }

    public function test_the_music_alias_with_a_hidden_sub_category_gets_the_audio_denied_page(): void
    {
        DB::table('user_excluded_categories')->insert(['users_id' => $this->viewer()->id, 'categories_id' => self::MP3]);
        $this->actingAs($this->viewer());
        $this->assertDenied($this->get('/browse/audio/MP3'), 'Audio - MP3');
        $this->assertDenied($this->get('/browse/music/MP3'), 'Audio - MP3');
    }

    private function viewer(): User
    {
        return $this->viewer ??= $this->browserUser();
    }

    private function hide(string $mode): void
    {
        if ($mode === 'root') {
            $this->revoke('view movies');
        } else {
            DB::table('user_excluded_categories')->insert(['users_id' => $this->viewer()->id, 'categories_id' => self::SD]);
            $this->actingAs($this->viewer());
        }
    }

    private function revoke(string $permission): void
    {
        $this->viewer()->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->viewer = $this->viewer()->fresh();
        $this->actingAs($this->viewer);
    }

    private function stored(string $name, int $category): void
    {
        $id = $this->release($name, ['categories_id' => $category, 'videos_id' => null, 'tv_episodes_id' => null, 'nfostatus' => 1]);
        DB::table('release_nfos')->insert(['releases_id' => $id, 'nfo' => 'NFO for '.$name]);
        file_put_contents($this->nzbDirectory.'/'.md5($name).'.nzb.gz', gzencode('<nzb>'.$name.'</nzb>'));
    }

    private function releaseId(string $name): int
    {
        return (int) DB::table('releases')->where('searchname', $name)->value('id');
    }

    /**
     * The category-disabled page, naming the hidden category and nothing of the release.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertDenied(TestResponse $response, string $name): void
    {
        $response->assertForbidden()->assertViewIs('errors.category-disabled')->assertViewHas('category', $name)
            ->assertSee($name.' is hidden in your account preferences.');
        $this->assertReleaseFree($response);
    }

    /**
     * A bare 403 carrying no release data.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertBare(TestResponse $response): void
    {
        $response->assertForbidden();
        $this->assertReleaseFree($response);
    }

    /** @param  TestResponse<Response>  $response */
    private function assertReleaseFree(TestResponse $response): void
    {
        $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
        $this->assertStringNotContainsString(self::HIDDEN, $content);
        $this->assertStringNotContainsString(md5(self::HIDDEN), $content);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    private function archiveEntries(TestResponse $response): array
    {
        $path = $this->makeTempPath('download', '.zip');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }
        $zip->close();

        return $entries;
    }
}
