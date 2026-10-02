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
        $this->assertSame(['Movies', 'TV', 'All'], $this->texts($xpath, '//nav[@aria-label="Main navigation"]//button[@aria-controls]'));
        $this->assertSame(0, $xpath->query('//*[@id="browse-menu"]')->length);
        $this->assertSame(0, $xpath->query('//header//a[@href="'.route('poster-identity').'"]')->length);
        $response->assertDontSee('Poster identities');
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Main navigation"]//a[@href="'.route('watchlist').'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="user-menu"]//a[@href="'.route('watchlist').'"]')->length);
        $response->assertDontSee('Trending')->assertDontSee('fa-fire', false);
        $response->assertSee('Upgrade Your Account');
        $this->assertSame(0, $xpath->query('//*[@id="sidebar" or @id="mobile-sidebar-toggle"]')->length);
        $this->assertSame(['All Movies', 'UHD', 'Films'], $this->texts($xpath, '//*[@id="nav-menu-movies"]//a'));
        $this->assertSame([route('movies.releases'), route('movies.releases', ['category' => [2040]]), route('movies.films')], $this->hrefs($xpath, '//*[@id="nav-menu-movies"]//a'));
        $this->assertSame(['All TV', 'HD', 'TV Shows', 'My Shows'], $this->texts($xpath, '//*[@id="nav-menu-tv"]//a'));
        $this->assertSame([route('tv.releases'), route('tv.releases', ['category' => [5030]]), route('tv.shows'), route('watchlist', ['tab' => 'tv'])], $this->hrefs($xpath, '//*[@id="nav-menu-tv"]//a'));
        $this->assertSame(1, $xpath->query('//*[@id="nav-menu-movies"]//a[@class="public-menu-root" and @href="'.route('movies.releases').'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="nav-menu-movies" or @id="nav-menu-tv"]//a//i')->length);
        $this->assertSame(['Browse by group', 'All Releases'], $this->texts($xpath, '//*[@id="nav-menu-all"]//a'));
        $this->assertSame([route('browsegroup'), route('browse.all')], $this->hrefs($xpath, '//*[@id="nav-menu-all"]//a'));
        $this->assertSame(0, $xpath->query('//header//*[@role="menu"][starts-with(@id, "nav-menu-")]')->length);
        $this->assertSame(0, $xpath->query('//form[@role="search"]//select')->length);
        $this->assertSame(1, $xpath->query('//form[@role="search"]//input[@type="hidden" and @name="t"]')->length);
        $this->assertSame(['All', 'Movies', 'TV'], $this->texts($xpath, '//form[@role="search"]//*[@role="menuitemradio"]'));
        $this->assertSame(0, $xpath->query('//a[@href="'.route('movies.releases', ['category' => [2030]]).'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@role="search"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('logout').'"]')->length);
    }

    public function test_every_root_has_its_own_header_menu_in_one_order_shared_with_the_search_scope(): void
    {
        DB::table('root_categories')->updateOrInsert(['id' => 1], ['title' => 'Other']);
        DB::table('categories')->updateOrInsert(['id' => 10], ['title' => 'Misc', 'root_categories_id' => 1]);

        $response = $this->actingAs($this->shellUser(['movies', 'tv', 'audio', 'console', 'books', 'pc', 'adult', 'other']))->get('/contact-us')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(['Movies', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other', 'All'], $this->texts($xpath, '//nav[@aria-label="Main navigation"]//button[@aria-controls]'));
        $this->assertSame(['All Movies', 'All TV', 'All Audio', 'All Books', 'All Console', 'All PC', 'All Adult', 'All Other'], $this->texts($xpath, '//nav//a[@class="public-menu-root"]'));
        $this->assertSame([route('movies.releases'), route('tv.releases'), url('/browse/audio'), route('books.releases'), route('console.releases'), route('pc.releases'), route('adult.releases'), url('/browse/other')], $this->hrefs($xpath, '//nav//a[@class="public-menu-root"]'));
        $this->assertSame(['All', 'Movies', 'TV', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'], $this->texts($xpath, '//form[@role="search"]//*[@role="menuitemradio"]'));
        $this->assertSame(['0', '2000', '5000', '3000', '7000', '1000', '4000', '6000', '1'], array_map(static fn (\DOMElement $item): string => $item->getAttribute('data-value'), iterator_to_array($xpath->query('//form[@role="search"]//*[@role="menuitemradio"]'))));
        foreach ($xpath->query('//nav[@aria-label="Main navigation"]//button[@aria-controls]') as $button) {
            $this->assertSame(1, $xpath->query('//*[@id="'.$button->getAttribute('aria-controls').'"]')->length);
        }
    }

    public function test_the_search_scope_starts_on_the_requested_root_or_on_all(): void
    {
        $user = $this->shellUser(['movies', 'tv']);
        foreach ([['5000', '5000', 'TV'], ['2040', '0', 'All'], ['', '0', 'All']] as [$requested, $value, $label]) {
            $this->resetGlobalComposerState();
            $xpath = $this->document($this->actingAs($user)->get('/contact-us?t='.$requested)->assertOk()->getContent());
            $this->assertSame($value, $xpath->query('//form[@role="search"]//input[@name="t"]')->item(0)->getAttribute('value'), $requested);
            $this->assertSame([$label], $this->texts($xpath, '//form[@role="search"]//*[@role="menuitemradio" and @aria-checked="true"]'), $requested);
            $this->assertSame('Search scope: '.$label, $xpath->query('//form[@role="search"]//button[@aria-haspopup="true"]')->item(0)->getAttribute('aria-label'), $requested);
        }
    }

    public function test_no_header_button_is_current_away_from_the_browsing_sections(): void
    {
        $xpath = $this->document($this->actingAs($this->shellUser(['movies', 'tv']))->get('/contact-us')->assertOk()->getContent());
        $this->assertSame(0, $xpath->query('//header//*[@aria-current]')->length);
    }

    public function test_watchlist_and_basket_counts_belong_to_the_current_user_and_are_fresh(): void
    {
        $user = $this->shellUser(['movies', 'tv']);
        DB::table('user_movies')->insert([['users_id' => $user->id, 'imdbid' => '123'], ['users_id' => 999, 'imdbid' => '456']]);
        DB::table('user_series')->insert(['users_id' => $user->id, 'videos_id' => 12]);
        DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => 1]);

        $response = $this->actingAs($user)->get('/contact-us')->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(['2'], $this->texts($xpath, '//*[@data-watchlist-count]'));
        $this->assertSame(['1', '1'], $this->texts($xpath, '//*[@data-basket-count]'));

        DB::table('user_movies')->where('users_id', $user->id)->delete();
        $this->resetGlobalComposerState();
        $response = $this->get('/contact-us')->assertOk();
        $this->assertSame(['1'], $this->texts($this->document($response->getContent()), '//*[@data-watchlist-count]'));
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
    private function hrefs(\DOMXPath $xpath, string $query): array
    {
        return array_map(static fn (\DOMElement $node): string => $node->getAttribute('href'), iterator_to_array($xpath->query($query)));
    }

    /** @return list<string> */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        return array_map(static fn (\DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($query)));
    }
}
