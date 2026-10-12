<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\View\Composers\AdminDataComposer;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * Issue #1056 Section C: pages are handed only the data their views read, and the admin layout
 * shows its flash messages on every admin page.
 */
final class DeadViewDataTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_an_admin_page_shows_the_flashed_success_error_and_warning_inside_the_layouts_main(): void
    {
        $messages = ['success' => 'The invented thing was saved', 'error' => 'The invented thing broke', 'warning' => 'The invented thing is nearly full'];
        $html = (string) $this->actingAs($this->admin())->withSession($messages)->get('/admin/content-add')->assertOk()->assertViewIs('admin.content.add')->getContent();

        $this->assertSame(1, preg_match('/<main\b[^>]*>(.*)<\/main>/s', $html, $main));
        foreach ([
            'success' => ['border-green-500', 'fa-check-circle'],
            'error' => ['border-red-500', 'fa-exclamation-circle'],
            'warning' => ['border-yellow-500', 'fa-exclamation-triangle'],
        ] as $kind => [$border, $icon]) {
            $this->assertMatchesRegularExpression(
                '/<div class="mb-4 p-4 [^"]*\b'.$border.'\b[^"]*">\s*<i class="fas '.$icon.' mr-2"><\/i>'.preg_quote($messages[$kind], '/').'\s*<\/div>/',
                $main[1],
                $kind,
            );
        }
        // the three messages come before the page's own content
        $this->assertLessThan(strpos($main[1], '<form'), strpos($main[1], $messages['warning']));
    }

    public function test_the_admin_composer_binds_the_site_settings_and_the_theme_alone(): void
    {
        $this->actingAs($this->admin());
        $view = view('layouts.admin');
        app(AdminDataComposer::class)->compose($view);

        $this->assertEqualsCanonicalizing(['site', 'userTheme'], array_keys($view->getData()));
    }

    public function test_the_terms_and_privacy_pages_are_handed_their_page_title_and_no_unread_meta(): void
    {
        foreach (['/terms-and-conditions', '/privacy-policy'] as $url) {
            $this->get($url)->assertOk()->assertViewHas('meta_title')
                ->assertViewMissing('title')->assertViewMissing('meta_keywords')->assertViewMissing('meta_description');
        }
    }

    public function test_the_contact_and_status_pages_keep_their_description_and_are_not_handed_a_title(): void
    {
        foreach (['service_statuses', 'service_incidents', 'service_incident_service_status'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        foreach (['/contact-us', '/status'] as $url) {
            $this->get($url)->assertOk()->assertViewHas('meta_description')->assertViewMissing('title');
        }
    }

    public function test_the_content_list_page_is_not_handed_the_admin_and_front_page_flags(): void
    {
        DB::table('content')->insert([
            ['id' => 1, 'title' => 'Invented front page', 'body' => 'Front', 'contenttype' => 3, 'status' => 1, 'role' => 0],
            ['id' => 2, 'title' => 'Invented help page', 'body' => 'Help', 'contenttype' => 2, 'status' => 1, 'role' => 0],
        ]);

        $this->actingAs($this->browserUser())->get('/content?page=content')->assertOk()->assertViewIs('content.index')
            ->assertSee('Browse our content pages')->assertSee('Invented help page')->assertDontSee('Invented front page')
            ->assertViewHas('content')->assertViewMissing('admin')->assertViewMissing('front');
    }

    public function test_the_rss_help_page_opens_without_looking_up_a_first_show_or_anime(): void
    {
        $this->actingAs($this->browserUser())->get('/rsshelp')->assertOk()->assertViewIs('rss.rssdesc')
            ->assertViewHas('categorylist')->assertViewMissing('show')->assertViewMissing('anidb');
    }
}
