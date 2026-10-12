<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\Support\ReadsRenderedMarkup;
use Tests\TestCase;

/**
 * Issue #1057: the admin list controls that did nothing, or opened an address no route answers.
 * Each test renders the page an administrator sees and follows the control to the request it sends.
 */
final class AdminListActionsTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;
    use ReadsRenderedMarkup;

    private ?User $administrator = null;

    private const REGEX_LISTS = [
        'category_regexes' => ['/admin/category_regexes-list', 'category_regex_delete'],
        'collection_regexes' => ['/admin/collection_regexes-list', 'collection_regex_delete'],
        'release_naming_regexes' => ['/admin/release_naming_regexes-list', 'release_naming_regex_delete'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage(itemsPerPage: 25);
        $this->withoutVite();
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_the_admin_confirmation_dialog_carries_the_marker_the_shared_modal_code_binds_escape_to(): void
    {
        ProductionTables::fromAuthority()->create('role_promotions');
        $html = $this->adminPage('/admin/promotions');
        $xpath = $this->xpath($html);

        $roots = $this->elements($xpath, '//*[@x-data="confirmModal"]');
        $this->assertCount(1, $roots);
        $this->assertTrue($roots[0]->hasAttribute('data-admin-confirm'));
        $dialogs = $this->elements($xpath, './/*[@data-modal-dialog]', $roots[0]);
        $this->assertCount(1, $dialogs);
        // the look is unchanged: the marker sits on the card, which keeps its classes
        $this->assertSame('bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-md w-full mx-4 transform transition-all', $dialogs[0]->getAttribute('class'));
        $this->assertCount(3, $this->elements($xpath, './/button', $dialogs[0]), 'close, Cancel and Confirm are inside the dialog');
    }

    public function test_group_edit_mounts_the_groups_component_on_its_footer_and_reset_group_calls_it(): void
    {
        ProductionTables::fromAuthority()->create('usenet_groups');
        $groupId = (int) DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.invented', 'description' => 'Invented group', 'active' => 1, 'backfill' => 0]);

        $html = $this->adminPage('/admin/group-edit?id='.$groupId);

        $roots = $this->openingTags($html, 'div', 'x-data="adminGroups"');
        $this->assertCount(1, $roots);
        $this->assertSame(url('/admin/ajax'), $this->attribute($roots[0], 'data-ajax-url'));
        $this->assertNotSame('', (string) $this->attribute($roots[0], 'data-csrf-token'));

        $xpath = $this->xpath($html);
        $buttons = $this->elements($xpath, '//*[@x-data="adminGroups"]//button[contains(normalize-space(.), "Reset Group")]');
        $this->assertCount(1, $buttons);
        $resets = $this->openingTags($html, 'button', "handleAction('reset-group'");
        $this->assertCount(1, $resets);
        $this->assertSame("handleAction('reset-group', '".$groupId."')", $this->attribute($resets[0], '@click'));
        $this->assertStringNotContainsString('data-action="reset-group"', $html);
        $this->assertStringNotContainsString('data-group-id', $html);
    }

    public function test_promotions_ask_before_activating_deactivating_and_deleting(): void
    {
        ProductionTables::fromAuthority()->create('role_promotions');
        DB::table('role_promotions')->insert([
            ['id' => 4, 'name' => 'Invented "Autumn" Bonus', 'applicable_roles' => '[1]', 'additional_days' => 30, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'name' => 'Invented Winter Bonus', 'applicable_roles' => '[1]', 'additional_days' => 7, 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $html = $this->adminPage('/admin/promotions');

        $links = $this->openingTags($html, 'a', 'x-data="confirmLink"');
        $this->assertCount(2, $links);
        foreach ([
            [4, 'Deactivate Promotion', 'Are you sure you want to deactivate the promotion "Invented "Autumn" Bonus"?', 'warning', 'Deactivate'],
            [9, 'Activate Promotion', 'Are you sure you want to activate the promotion "Invented Winter Bonus"?', 'success', 'Activate'],
        ] as $index => [$id, $title, $message, $type, $confirmText]) {
            $link = $links[$index];
            $this->assertSame(route('admin.promotions.toggle', $id), $this->attribute($link, 'href'));
            $this->assertSame(route('admin.promotions.toggle', $id), $this->attribute($link, 'data-url'));
            $this->assertSame($title, $this->attribute($link, 'data-title'));
            $this->assertSame($message, $this->attribute($link, 'data-message'));
            $this->assertSame($type, $this->attribute($link, 'data-type'));
            $this->assertSame($confirmText, $this->attribute($link, 'data-confirm-text'));
            $this->assertSame('navigate()', $this->attribute($link, '@click.prevent'));
        }

        $forms = $this->openingTags($html, 'form', 'x-data="confirmForm"');
        $this->assertCount(2, $forms);
        foreach ([[4, 'Invented "Autumn" Bonus'], [9, 'Invented Winter Bonus']] as $index => [$id, $name]) {
            $form = $forms[$index];
            $this->assertSame(route('admin.promotions.destroy', $id), $this->attribute($form, 'action'));
            $this->assertSame('POST', strtoupper((string) $this->attribute($form, 'method')));
            $this->assertSame('Delete Promotion', $this->attribute($form, 'data-title'));
            $this->assertSame('Are you sure you want to delete the promotion "'.$name.'"? This action cannot be undone.', $this->attribute($form, 'data-message'));
            $this->assertSame('danger', $this->attribute($form, 'data-type'));
            $this->assertSame('Delete', $this->attribute($form, 'data-confirm-text'));
            $this->assertSame('submit()', $this->attribute($form, '@submit.prevent'));
        }

        $xpath = $this->xpath($html);
        foreach ($this->elements($xpath, '//form[@x-data="confirmForm"]') as $form) {
            $this->assertCount(1, $this->elements($xpath, './/input[@name="_method"][@value="DELETE"]', $form));
            $this->assertCount(1, $this->elements($xpath, './/input[@name="_token"]', $form));
            $this->assertCount(1, $this->elements($xpath, './/button[@type="submit"][@title="Delete"]', $form));
        }
        $this->assertStringNotContainsString('promotion-toggle-btn', $html);
        $this->assertStringNotContainsString('promotion-delete-btn', $html);
    }

    public function test_each_regex_list_and_the_blacklist_list_deletes_a_row_through_the_admin_ajax_endpoint(): void
    {
        $this->createRegexAndBlacklistTables();
        foreach (array_keys(self::REGEX_LISTS) as $table) {
            DB::table($table)->insert([
                ['id' => 3, 'group_regex' => 'alt\.binaries\.invented', 'regex' => '/^invented-(?P<name>.+)$/i', 'description' => 'Invented rule three', 'status' => 1, 'ordinal' => 1],
                ['id' => 8, 'group_regex' => 'alt\.binaries\.invented', 'regex' => '/^made-up-(?P<name>.+)$/i', 'description' => 'Invented rule eight', 'status' => 1, 'ordinal' => 2],
            ]);
        }
        DB::table('binaryblacklist')->insert([
            ['id' => 3, 'groupname' => 'alt.binaries.invented', 'regex' => 'invented-spam', 'description' => 'Invented blacklist three', 'status' => 1, 'optype' => 1, 'msgcol' => 1],
            ['id' => 8, 'groupname' => 'alt.binaries.invented', 'regex' => 'made-up-spam', 'description' => 'Invented blacklist eight', 'status' => 1, 'optype' => 1, 'msgcol' => 1],
        ]);

        $pages = [];
        foreach (self::REGEX_LISTS as [$url, $action]) {
            $pages[] = [$url, $action, 'Are you sure you want to delete this regex?', 'Regex deleted', 'Delete this regex'];
        }
        $pages[] = ['/admin/binaryblacklist-list', 'binary_blacklist_delete', 'Are you sure? This will delete the blacklist.', 'Blacklist deleted', 'Delete this blacklist'];

        foreach ($pages as [$url, $action, $question, $deleted, $buttonTitle]) {
            $html = $this->adminPage($url);
            $xpath = $this->xpath($html);

            $roots = $this->openingTags($html, 'span', 'x-data="adminRowDelete"');
            $this->assertCount(2, $roots, $url);
            foreach ($roots as $index => $root) {
                $this->assertSame(route('admin.ajax'), $this->attribute($root, 'data-ajax-url'), $url);
                $this->assertSame($action, $this->attribute($root, 'data-ajax-action'), $url);
                $this->assertSame(['3', '8'][$index], $this->attribute($root, 'data-row-id'), $url);
                $this->assertSame($question, $this->attribute($root, 'data-question'), $url);
                $this->assertSame($deleted, $this->attribute($root, 'data-deleted-message'), $url);
                $this->assertNotSame('', (string) $this->attribute($root, 'data-csrf-token'), $url);
            }

            // every delete button is the one button of a root, and the row the root names is on the page
            $this->assertCount(2, $this->elements($xpath, '//button[@title="'.$buttonTitle.'"]'), $url);
            $this->assertCount(2, $this->elements($xpath, '//*[@x-data="adminRowDelete"][count(.//button)=1]/button[@type="button"][@title="'.$buttonTitle.'"]'), $url);
            $this->assertCount(2, $this->openingTags($html, 'button', '@click="remove"'), $url);
            foreach (['3', '8'] as $id) {
                $this->assertCount(1, $this->elements($xpath, '//tr[@id="row-'.$id.'"]//*[@x-data="adminRowDelete"][@data-row-id="'.$id.'"]'), $url);
            }

            foreach (['data-delete-url', 'data-delete-regex', 'data-delete-blacklist'] as $gone) {
                $this->assertStringNotContainsString($gone, $html, $url);
            }
        }
    }

    public function test_the_admin_ajax_endpoint_deletes_the_named_regex_or_blacklist_row_and_answers_200(): void
    {
        $this->createRegexAndBlacklistTables();
        $admin = $this->admin();
        $this->expectOutputString('Regex 3 deleted.Regex 3 deleted.Regex 3 deleted.Blacklist 3 deleted.');

        foreach ([...array_map(static fn (array $list): string => $list[1], self::REGEX_LISTS), 'binaryblacklist' => 'binary_blacklist_delete'] as $table => $action) {
            foreach ([3, 8] as $id) {
                DB::table($table)->insert(['id' => $id, 'regex' => 'invented-'.$id, 'description' => 'Invented row '.$id, 'status' => 1]);
            }

            $this->actingAs($admin)->post(route('admin.ajax'), ['action' => $action, 'row_id' => '3'])->assertOk();

            $this->assertSame([8], DB::table($table)->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), $table);
        }
    }

    public function test_every_add_new_regex_button_on_the_category_regex_list_opens_the_edit_page(): void
    {
        $this->createRegexAndBlacklistTables();
        $edit = url('/admin/category_regexes-edit');

        $empty = $this->xpath($this->adminPage('/admin/category_regexes-list'));
        $this->assertSame([$edit, $edit, $edit], $this->addNewRegexLinks($empty), 'header, empty box and footer');

        DB::table('category_regexes')->insert(['id' => 3, 'group_regex' => 'alt\.binaries\.invented', 'regex' => '/^invented$/i', 'description' => 'Invented rule', 'status' => 1, 'ordinal' => 1]);
        $filled = $this->xpath($this->adminPage('/admin/category_regexes-list'));
        $this->assertSame([$edit, $edit], $this->addNewRegexLinks($filled), 'header and footer');

        $this->adminPage($edit);
    }

    public function test_the_invitations_list_keeps_every_row_form_outside_the_bulk_form(): void
    {
        ProductionTables::fromAuthority()->create('invitations');
        $admin = $this->admin();
        foreach ([[11, 'invented-token-eleven', 'first.invitee@example.test'], [12, 'invented-token-twelve', 'second.invitee@example.test']] as [$id, $token, $email]) {
            DB::table('invitations')->insert([
                'id' => $id, 'token' => $token, 'email' => $email, 'invited_by' => $admin->id, 'expires_at' => now()->addWeek(),
                'is_active' => 1, 'created_at' => now()->subMinutes($id), 'updated_at' => now(),
            ]);
        }

        $html = (string) $this->actingAs($admin)->get('/admin/invitations')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $roots = $this->elements($xpath, '//*[@x-data="adminInvitations"]');
        $this->assertCount(1, $roots);
        $this->assertCount(1, $this->elements($xpath, './/input[@id="select_all"]', $roots[0]));
        $this->assertCount(2, $this->elements($xpath, './/input[contains(concat(" ", @class, " "), " invitation-checkbox ")]', $roots[0]));
        $this->assertCount(1, $this->openingTags($html, 'input', '@change="toggleAll"'));
        $this->assertSame('select_all', $this->attribute($this->openingTags($html, 'input', '@change="toggleAll"')[0], 'id'));
        $this->assertCount(2, $this->openingTags($html, 'input', '@change="syncHead"'));

        // no form is written inside the bulk form
        $this->assertSame(1, preg_match('/<form\b[^>]*\bid="bulkForm"[^>]*>(.*?)<\/form>/s', $html, $bulk));
        $this->assertStringNotContainsString('<form', $bulk[1]);
        $bulkTag = $this->openingTags($html, 'form', 'id="bulkForm"')[0];
        $this->assertSame(url('admin/invitations/bulk'), $this->attribute($bulkTag, 'action'));
        $this->assertSame('POST', strtoupper((string) $this->attribute($bulkTag, 'method')));
        $this->assertCount(1, $this->elements($xpath, '//form[@id="bulkForm"]//input[@name="_token"]'));

        // each row has its own resend and cancel form, with its own CSRF field and submit button
        foreach ([11, 12] as $id) {
            foreach (['resend', 'cancel'] as $action) {
                $forms = $this->elements($xpath, '//form[@action="'.url('admin/invitations/'.$id.'/'.$action).'"]');
                $this->assertCount(1, $forms, $id.' '.$action);
                $this->assertSame('POST', strtoupper($forms[0]->getAttribute('method')));
                $this->assertCount(1, $this->elements($xpath, './/input[@name="_token"]', $forms[0]));
                $this->assertCount(1, $this->elements($xpath, './/button[@type="submit"]', $forms[0]));
                $this->assertCount(0, $this->elements($xpath, 'ancestor::form', $forms[0]));
            }
        }

        // the row boxes, the bulk-action select and Apply still submit together to the bulk address
        $inBulk = '[ancestor::form[@id="bulkForm"] or @form="bulkForm"]';
        $boxes = $this->elements($xpath, '//input[contains(concat(" ", @class, " "), " invitation-checkbox ")]'.$inBulk);
        $this->assertSame(['11', '12'], array_map(static fn (\DOMElement $box): string => $box->getAttribute('value'), $boxes));
        foreach ($boxes as $box) {
            $this->assertSame('invitation_ids[]', $box->getAttribute('name'));
        }
        $this->assertCount(1, $this->elements($xpath, '//select[@name="bulk_action"]'.$inBulk));
        $apply = $this->elements($xpath, '//button[@type="submit"][normalize-space(.)="Apply"]'.$inBulk);
        $this->assertCount(1, $apply);
        $this->assertSame('Are you sure you want to perform this bulk action?', $apply[0]->getAttribute('data-confirm'));
    }

    public function test_comment_delete_is_a_confirmed_post_that_deletes_the_comment(): void
    {
        $this->createReleaseSchema();
        ProductionTables::fromAuthority()->create('release_comments');
        $admin = $this->admin();
        $releaseId = $this->release('Invented.Commented.Release');
        foreach ([21, 22] as $id) {
            DB::table('release_comments')->insert([
                'id' => $id, 'releases_id' => $releaseId, 'text' => 'Invented comment '.$id, 'isvisible' => 1, 'username' => $admin->username,
                'users_id' => $admin->id, 'created_at' => now()->subMinutes($id), 'updated_at' => now(),
            ]);
        }

        $html = (string) $this->actingAs($admin)->get('/admin/comments-list')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        foreach ([21, 22] as $id) {
            $forms = $this->elements($xpath, '//form[@action="'.route('admin.comments-delete', $id).'"]');
            $this->assertCount(1, $forms);
            $this->assertSame('POST', strtoupper($forms[0]->getAttribute('method')));
            $this->assertCount(1, $this->elements($xpath, './/input[@name="_token"]', $forms[0]));
            $this->assertCount(1, $this->elements($xpath, './/button[@type="submit"][@title="Delete"][@data-confirm-delete]', $forms[0]));
        }
        $this->assertStringNotContainsString('comment-delete?id=', $html);

        $this->actingAs($admin)->from('/admin/comments-list')->post(route('admin.comments-delete', 21))
            ->assertRedirect('/admin/comments-list')->assertSessionHas('success', 'Comment deleted successfully');
        $this->assertSame([22], DB::table('release_comments')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

        $this->actingAs($admin)->get('/admin/comments-list')->assertOk()
            ->assertSee('Invented comment 22')->assertDontSee('Invented comment 21');
    }

    public function test_anidb_remove_from_releases_is_a_single_click_post_that_shows_the_result_page(): void
    {
        $this->createReleaseSchema();
        $this->createAnidbTables();
        $admin = $this->admin();
        DB::table('anidb_titles')->insert(['anidbid' => 501, 'type' => 'main', 'lang' => 'en', 'title' => 'Invented Anime Title']);
        DB::table('anidb_info')->insert(['anidbid' => 501, 'type' => 'TV Series']);

        $html = (string) $this->actingAs($admin)->get('/admin/anidb-list')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $forms = $this->elements($xpath, '//form[@action="'.route('admin.anidb-delete', 501).'"]');
        $this->assertCount(1, $forms);
        $this->assertSame('POST', strtoupper($forms[0]->getAttribute('method')));
        $this->assertCount(1, $this->elements($xpath, './/input[@name="_token"]', $forms[0]));
        $buttons = $this->elements($xpath, './/button[@type="submit"][@title="Remove from Releases"]', $forms[0]);
        $this->assertCount(1, $buttons);
        // a single click, as the link was: nothing asks for confirmation
        $this->assertFalse($forms[0]->hasAttribute('x-data'));
        $this->assertFalse($buttons[0]->hasAttribute('data-confirm'));
        $this->assertFalse($buttons[0]->hasAttribute('data-confirm-delete'));
        $this->assertCount(0, $this->elements($xpath, '//a[contains(@href, "anidb-delete")]'));

        $this->actingAs($admin)->post(route('admin.anidb-delete', 501))->assertOk()->assertViewIs('admin.anidb.remove');
    }

    public function test_role_delete_is_a_confirmed_post_that_deletes_the_role(): void
    {
        $admin = $this->admin();
        $roleId = (int) Role::query()->create(['name' => "Invented 'Guest' Role", 'guard_name' => 'web'])->id;
        $adminRoleId = (int) $admin->roles_id;

        $html = (string) $this->actingAs($admin)->get('/admin/role-list')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $forms = $this->openingTags($html, 'form', 'x-data="confirmForm"');
        $this->assertCount(2, $forms, 'one per role');
        foreach ($forms as $form) {
            $this->assertSame(route('admin.role-delete'), $this->attribute($form, 'action'));
            $this->assertSame('POST', strtoupper((string) $this->attribute($form, 'method')));
            $this->assertSame('submit()', $this->attribute($form, '@submit.prevent'));
        }
        $messages = array_map(fn (string $form): ?string => $this->attribute($form, 'data-message'), $forms);
        $this->assertContains("Are you sure you want to delete role 'Invented 'Guest' Role'?", $messages);
        $this->assertContains("Are you sure you want to delete role 'Admin'?", $messages);

        $domForms = $this->elements($xpath, '//form[@x-data="confirmForm"][@action="'.route('admin.role-delete').'"]');
        $this->assertCount(2, $domForms);
        $ids = [];
        foreach ($domForms as $form) {
            $this->assertCount(1, $this->elements($xpath, './/input[@name="_token"]', $form));
            $this->assertCount(1, $this->elements($xpath, './/button[@type="submit"][@title="Delete"]', $form));
            $hidden = $this->elements($xpath, './/input[@type="hidden"][@name="id"]', $form);
            $this->assertCount(1, $hidden);
            $ids[] = (int) $hidden[0]->getAttribute('value');
        }
        $this->assertEqualsCanonicalizing([$adminRoleId, $roleId], $ids);
        $this->assertCount(0, $this->elements($xpath, '//*[contains(@href, "role-delete") or contains(@data-url, "role-delete")]'));

        $this->actingAs($admin)->from('/admin/role-list')->post(route('admin.role-delete'), ['id' => $roleId])->assertRedirect('/admin/role-list');
        $this->assertSame([$adminRoleId], DB::table('roles')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    public function test_the_failed_releases_file_count_is_a_plain_pill_and_the_name_still_opens_the_edit_page(): void
    {
        $this->createReleaseSchema();
        ProductionTables::fromAuthority()->create('dnzb_failures');
        $admin = $this->admin();
        $releaseId = $this->release('Invented.Failed.Release', ['totalpart' => 37]);
        $guid = (string) DB::table('releases')->where('id', $releaseId)->value('guid');
        DB::table('dnzb_failures')->insert(['release_id' => $releaseId, 'users_id' => $admin->id, 'failed' => 1]);

        $html = (string) $this->actingAs($admin)->get('/admin/failrel-list')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $pills = $this->elements($xpath, '//span[i[contains(concat(" ", @class, " "), " fa-file ")]]');
        $this->assertCount(1, $pills);
        $this->assertSame('37', trim($pills[0]->textContent));
        $this->assertSame(
            'inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200',
            $pills[0]->getAttribute('class'),
        );
        $this->assertFalse($pills[0]->hasAttribute('href'));
        $this->assertCount(0, $this->elements($xpath, 'ancestor::a', $pills[0]));
        $this->assertCount(0, $this->elements($xpath, '//a[.//i[contains(concat(" ", @class, " "), " fa-file ")]]'));
        $this->assertStringNotContainsString('release-files', $html);

        $names = $this->elements($xpath, '//a[@href="'.url('/admin/release-edit?id='.$guid).'"][@title="Invented.Failed.Release"]');
        $this->assertCount(1, $names);
        $this->assertSame('Invented.Failed.Release', trim($names[0]->textContent));
    }

    /**
     * Every page of one test is opened by the same signed-in administrator.
     */
    private function adminPage(string $url): string
    {
        $this->administrator ??= $this->admin();

        return (string) $this->actingAs($this->administrator)->get($url)->assertOk()->getContent();
    }

    private function createRegexAndBlacklistTables(): void
    {
        foreach ([...array_keys(self::REGEX_LISTS), 'binaryblacklist'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        ProductionTables::fromAuthority()->create('usenet_groups', ['id', 'name', 'active']);
    }

    /**
     * @return list<string>
     */
    private function addNewRegexLinks(\DOMXPath $xpath): array
    {
        return array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            $this->elements($xpath, '//a[contains(normalize-space(.), "Add New Regex")]'),
        );
    }
}
