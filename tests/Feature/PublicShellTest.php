<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithPublicShell;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class PublicShellTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithPublicShell;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        foreach ([2000 => 'Movies', 5000 => 'TV', 3000 => 'Audio', 1000 => 'Console', 7000 => 'Books', 4000 => 'PC', 6000 => 'XXX'] as $id => $title) {
            DB::table('root_categories')->insert(['id' => $id, 'title' => $title]);
            DB::table('categories')->insert(['id' => $id + 30, 'title' => 'HD', 'root_categories_id' => $id]);
        }
        DB::table('categories')->insert(['id' => 2040, 'title' => 'UHD', 'root_categories_id' => 2000]);
        $this->createPublicShellCountTables();
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_header_uses_the_same_authorized_roots_for_browse_and_search_without_a_sidebar(): void
    {
        $user = $this->shellUser(['movies', 'tv', 'audio']);
        DB::table('user_excluded_categories')->insert([
            ['users_id' => $user->id, 'categories_id' => 2030],
            ['users_id' => $user->id, 'categories_id' => 3030],
        ]);

        $response = $this->actingAs($user)->get('/contact-us')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//header[@data-public-header]')->length);
        $this->assertSame(1, $xpath->query('//button[@aria-controls="browse-menu" and @aria-label="Browse categories"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="browse-menu"]//a[@href="'.route('watchlist', ['tab' => 'tv']).'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="browse-menu"]//a[@href="'.route('trending-movies').'"]')->length);
        $response->assertSee('Upgrade Your Account');
        $this->assertSame(0, $xpath->query('//*[@id="sidebar" or @id="mobile-sidebar-toggle"]')->length);
        $this->assertSame(['Movies', 'TV'], $this->texts($xpath, '//*[@id="browse-menu"]//a[@data-browse-root]'));
        $this->assertSame(['All', 'Movies', 'TV'], $this->texts($xpath, '//form[@role="search"]//select[@name="t"]/option'));
        $this->assertSame(1, $xpath->query('//a[@href="'.url('/browse/movies/2040').'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.url('/browse/movies/2030').'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@role="search"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('logout').'"]')->length);
    }

    public function test_watchlist_and_basket_counts_belong_to_the_current_user_and_are_fresh(): void
    {
        $user = $this->shellUser(['movies', 'tv']);
        DB::table('user_movies')->insert([['users_id' => $user->id, 'imdbid' => '123'], ['users_id' => 999, 'imdbid' => '456']]);
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => 12]);
        DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => 1]);

        $response = $this->actingAs($user)->get('/contact-us')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(['2', '2'], $this->texts($xpath, '//*[@data-watchlist-count]'));
        $this->assertSame(['1', '1'], $this->texts($xpath, '//*[@data-basket-count]'));

        DB::table('user_movies')->where('users_id', $user->id)->delete();
        $this->resetGlobalComposerState();
        $response = $this->get('/contact-us')->assertOk();
        $this->assertSame(['1', '1'], $this->texts($this->document($response->getContent()), '//*[@data-watchlist-count]'));
    }

    public function test_guests_keep_minimal_public_pages_and_cannot_render_the_authenticated_shell(): void
    {
        $response = $this->get('/contact-us')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(0, $xpath->query('//header[@data-public-header]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="theme-toggle"]')->length);
        Route::get('/shell-contract', fn () => view('layouts.main'));
        $this->get('/shell-contract')->assertRedirect(route('login'));
    }

    public function test_shared_search_explains_its_keyboard_shortcut(): void
    {
        $this->actingAs($this->shellUser(['tv']))->get('/contact-us')->assertOk()
            ->assertSee('placeholder="Search releases… (Press / to search)"', false);
    }

    public function test_only_administrators_have_dashboard_navigation_in_the_user_menu(): void
    {
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        foreach (['User', 'Moderator', 'Admin'] as $role) {
            $this->flushSession();
            $this->resetGlobalComposerState();
            $response = $this->actingAs($this->createUserWithRole($role))->get('/contact-us')->assertOk();
            $xpath = $this->document($response->getContent());
            $this->assertSame($role === 'Admin' ? 1 : 0, $xpath->query('//*[@id="user-menu"]//a[@href="'.route('admin.index').'"]')->length, $role);
        }
    }

    /** @param list<string> $roots */
    private function shellUser(array $roots): User
    {
        $user = $this->createUserWithRole('User');
        foreach ($roots as $root) {
            $permission = Permission::findOrCreate('view '.$root, 'web');
            $user->givePermissionTo($permission);
        }

        return $user->fresh();
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    /** @return list<string> */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        return array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($query)));
    }
}
