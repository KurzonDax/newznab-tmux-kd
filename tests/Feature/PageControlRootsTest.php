<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\Support\ReadsRenderedMarkup;
use Tests\TestCase;

/**
 * Issue #1057: the show-password eye and the RSS help copy buttons get their behaviour from a
 * component root on the page, which is what makes the script loader fetch their script; and the
 * footer's Admin link opens the admin home.
 */
final class PageControlRootsTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;
    use ReadsRenderedMarkup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        ProductionTables::fromAuthority()->create('registration_periods');
        DB::table('settings')->upsert([['name' => 'registerstatus', 'value' => '0']], ['name'], ['value']);
        config(['captcha.enabled' => false, 'captcha.recaptcha.enabled' => false, 'captcha.turnstile.enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_sign_in_page_mounts_one_show_password_eye(): void
    {
        $html = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertPasswordEyes($html, ['password']);
    }

    public function test_the_registration_page_mounts_an_eye_on_each_of_its_two_password_fields(): void
    {
        $html = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertPasswordEyes($html, ['password', 'password_confirmation']);
    }

    public function test_the_admin_user_edit_page_mounts_one_show_password_eye(): void
    {
        foreach (['passkeys', 'user_role_history'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        $admin = $this->admin();

        $html = (string) $this->actingAs($admin)->get('/admin/user-edit?id='.$admin->id)->assertOk()->getContent();

        $this->assertPasswordEyes($html, ['password']);
    }

    public function test_every_rss_help_address_has_its_own_copy_button(): void
    {
        $this->createReleaseSchema();
        DB::table('categories')->insert(['id' => 9, 'title' => '', 'root_categories_id' => 1, 'description' => 'Untitled', 'status' => 1]);
        $user = $this->browserUser();

        $html = (string) $this->actingAs($user)->get('/rsshelp')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $roots = $this->elements($xpath, '//*[@x-data="copyToClipboard"]');
        $copied = [];
        foreach ($roots as $root) {
            $inputs = $this->elements($xpath, './/input', $root);
            $buttons = $this->elements($xpath, './/button', $root);
            $this->assertCount(1, $inputs);
            $this->assertCount(1, $buttons);
            $this->assertSame('button', $buttons[0]->getAttribute('type'));
            $this->assertNotSame('', $inputs[0]->getAttribute('value'));
            $copied[] = $inputs[0]->getAttribute('id');
        }
        $this->assertSame([
            'apiTokenInput', 'fullFeedUrl', 'cartFeedUrl', 'myShowsFeedUrl', 'myMoviesFeedUrl', 'trendingMoviesFeedUrl', 'trendingShowsFeedUrl',
            'parentCat1Url', 'subCat1Url',
        ], $copied, 'the token, six fixed feeds, the parent category and the titled sub-category');

        // each button copies the input of its own group, and its icon is the one that turns into the check mark
        $buttons = $this->openingTags($html, 'button', '@click="copy(');
        $this->assertSame(
            array_map(static fn (string $id): string => "copy('".$id."')", $copied),
            array_map(fn (string $button): ?string => $this->attribute($button, '@click'), $buttons),
        );
        $this->assertCount(9, $this->openingTags($html, 'i', ":class=\"copied ? 'fa-check' : 'fa-copy'\""));

        $this->assertSame('api_token='.$user->api_token, $this->elements($xpath, '//input[@id="apiTokenInput"]')[0]->getAttribute('value'));
        $this->assertCount(0, $this->elements($xpath, '//*[contains(concat(" ", @class, " "), " copy-btn ")]'));
        $this->assertCount(0, $this->elements($xpath, '//*[@id="copyApiToken"]'));
        $this->assertStringNotContainsString('data-copy-target', $html);
    }

    public function test_the_footer_admin_link_opens_the_admin_home_for_administrators_only(): void
    {
        $this->createReleaseSchema();

        $admin = $this->xpath((string) $this->actingAs($this->admin())->get('/rsshelp')->assertOk()->getContent());
        $links = $this->elements($admin, '//nav[@aria-label="Site links"]/a[normalize-space(.)="Admin"]');
        $this->assertCount(1, $links);
        $this->assertSame(route('admin.index'), $links[0]->getAttribute('href'));
        $this->assertSame(url('/admin/index'), $links[0]->getAttribute('href'));
    }

    public function test_the_footer_has_no_admin_link_for_other_users(): void
    {
        $this->createReleaseSchema();

        $user = $this->xpath((string) $this->actingAs($this->browserUser())->get('/rsshelp')->assertOk()->getContent());

        $this->assertCount(5, $this->elements($user, '//nav[@aria-label="Site links"]/a[not(@target)]'), 'Forum, API, API v2, RSS feeds, Status');
        $this->assertCount(0, $this->elements($user, '//nav[@aria-label="Site links"]/a[normalize-space(.)="Admin"]'));
    }

    /**
     * Each named password field sits in its own `passwordToggle` root with one eye button:
     * the field is the root's `field` ref, the button calls `toggle`, and the icon follows `iconClass()`.
     *
     * @param  list<string>  $fieldNames
     */
    private function assertPasswordEyes(string $html, array $fieldNames): void
    {
        $xpath = $this->xpath($html);
        $roots = $this->elements($xpath, '//*[@x-data="passwordToggle"]');
        $this->assertCount(count($fieldNames), $roots);

        foreach ($roots as $index => $root) {
            $inputs = $this->elements($xpath, './/input', $root);
            $this->assertCount(1, $inputs);
            $this->assertSame('password', $inputs[0]->getAttribute('type'));
            $this->assertSame($fieldNames[$index], $inputs[0]->getAttribute('name'));
            $this->assertSame('field', $inputs[0]->getAttribute('x-ref'));

            $buttons = $this->elements($xpath, './/button', $root);
            $this->assertCount(1, $buttons);
            $this->assertSame('button', $buttons[0]->getAttribute('type'));
            $icons = $this->elements($xpath, './/i', $buttons[0]);
            $this->assertCount(1, $icons);
            $this->assertSame('fas', $icons[0]->getAttribute('class'));
        }

        $this->assertCount(count($fieldNames), $this->openingTags($html, 'button', '@click="toggle"'));
        $this->assertCount(count($fieldNames), $this->openingTags($html, 'i', 'class="fas" :class="iconClass()"'));
        $this->assertCount(count($fieldNames), $this->elements($xpath, '//input[@type="password"]'), 'every password field has its eye');
        $this->assertStringNotContainsString('password-toggle-btn', $html);
        $this->assertStringNotContainsString('data-field-id', $html);
        $this->assertCount(0, array_filter(
            $this->elements($xpath, '//*[@id]'),
            static fn (DOMElement $element): bool => str_ends_with($element->getAttribute('id'), '-eye'),
        ));
    }
}
